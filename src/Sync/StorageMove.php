<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Sync;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\StorageKitBundle\Audit\AuditRecorder;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Message\RunStorageSyncMessage;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Repository\StorageSyncRepository;
use Nowo\StorageKitBundle\Settings\InstanceStorageSettings;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function count;
use function implode;
use function mb_substr;

/**
 * Moving an owner's files (a scope, or null: the instance) to another bucket from its Move page: the *target* profile
 * (the new bucket), the sync from the active profile to it (Messenger when a bus is available, else
 * `nowo:storage-kit:sync`), and *Switch*: only after a completed, verified sync with no file uploaded since, every
 * file of the active profile is re-pointed to the target in one transaction, the target becomes the owner's profile
 * and the old one is retired (kept: a file written there while switching still downloads; its objects are left for the
 * owner to delete). Every step is audited (never the secret). An environment-managed instance profile cannot be
 * switched here.
 */
final readonly class StorageMove
{
    /** Switch done. */
    public const SWITCHED = 'switched';

    /** No completed sync. */
    public const NOT_SYNCED = 'not_synced';

    /** Files were uploaded after the sync. */
    public const NEW_FILES = 'new_files';

    /** The instance profile is managed by the configuration. */
    public const ENV_MANAGED = 'env_managed';

    /** Switch result: the `storage` section refused the target's connection; nothing changed. */
    public const SETTINGS_REFUSED = 'settings_refused';

    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private StorageProfileRepository $profiles,
        private StorageSyncRepository $syncs,
        private StorageProfiles $resolver,
        private StorageSyncer $syncer,
        private StorageEntityManager $entityManager,
        private AuditRecorder $audit,
        private ?MessageBusInterface $bus = null,
        private ?InstanceStorageSettings $instanceSettings = null,
    ) {
    }

    /**
     * Whether syncs run in the background (a Messenger bus is available).
     */
    public function runsInBackground(): bool
    {
        return $this->bus instanceof MessageBusInterface;
    }

    /**
     * The owner's target profile, or a new unsaved one.
     */
    public function target(?string $scope): StorageProfile
    {
        return $this->profiles->targetOf($scope) ?? new StorageProfile($scope, StorageProfile::STATE_TARGET);
    }

    /**
     * The sync from the owner's active profile to its target, if one was started.
     */
    public function sync(?StorageProfile $active, ?StorageProfile $target): ?StorageSync
    {
        return $active instanceof StorageProfile && $active->getId() !== null && $target instanceof StorageProfile && $target->getId() !== null
            ? $this->syncs->forPair($active, $target)
            : null;
    }

    /**
     * Saves the target bucket.
     *
     * @param list<string> $fields names of the changed fields
     */
    public function saveTarget(StorageProfile $target, ?object $actor, array $fields): void
    {
        $manager = $this->entityManager->manager();
        $manager->persist($target);
        $manager->flush();
        $this->audit->record(StorageAuditEvent::MOVE_TARGET_SAVED, $target->getScope(), $actor, ['profile' => $target->getUuid(), 'fields' => implode(',', $fields)]);
    }

    /**
     * Queues the sync (new or resumed) and dispatches it when a bus is available.
     */
    public function start(StorageProfile $active, StorageProfile $target, ?object $actor): StorageSync
    {
        $sync = $this->syncer->open($active, $target);
        // @igor-ignore - Doctrine entity of the current unit of work, not a shared service.
        $sync->setStartedBy($actor instanceof UserInterface ? mb_substr($actor->getUserIdentifier(), 0, 180) : null);
        $this->entityManager->manager()->flush();
        $this->audit->record(StorageAuditEvent::MOVE_SYNC_STARTED, $target->getScope(), $actor, ['sync' => $sync->getUuid(), 'from' => $active->getUuid(), 'to' => $target->getUuid()]);
        $this->bus?->dispatch(new RunStorageSyncMessage((int) $sync->getId()));

        return $sync;
    }

    /**
     * Cancels an open sync.
     */
    public function cancel(StorageSync $sync, ?object $actor): void
    {
        $this->syncer->cancel($sync);
        $this->audit->record(StorageAuditEvent::MOVE_SYNC_CANCELLED, $sync->getTo()->getScope(), $actor, ['sync' => $sync->getUuid()]);
    }

    /**
     * Forgets the target (and its syncs); objects already copied stay in that bucket.
     */
    public function discard(StorageProfile $target, ?object $actor): void
    {
        $uuid    = $target->getUuid();
        $manager = $this->entityManager->manager();
        // @igor-ignore - Entity manager of the current unit of work (reset between requests by Doctrine).
        $manager->remove($target);
        $manager->flush();
        $this->audit->record(StorageAuditEvent::MOVE_TARGET_DISCARDED, $target->getScope(), $actor, ['profile' => $uuid]);
    }

    /**
     * Switches the owner to the target bucket.
     *
     * @return self::ENV_MANAGED|self::NEW_FILES|self::NOT_SYNCED|self::SETTINGS_REFUSED|self::SWITCHED
     */
    public function switch(StorageProfile $active, StorageProfile $target, ?object $actor): string
    {
        if ($this->resolver->isEnvManaged($active->getScope())) {
            return self::ENV_MANAGED;
        }
        $sync = $this->sync($active, $target);
        if (!$sync instanceof StorageSync || !$sync->isCompleted()) {
            return self::NOT_SYNCED;
        }
        if ($this->syncer->remaining($sync) > 0) {
            return self::NEW_FILES;
        }
        $manager  = $this->entityManager->manager();
        $files    = $this->entityManager->table(StoredFile::class);
        $moved    = 0;
        $settings = $active->isSettingsBacked() ? $this->instanceSettings : null;
        $previous = $settings?->connection();
        $secret   = $settings?->secret();
        try {
            $this->swap($manager, $files, $active, $target, $sync, $settings, $previous, $secret, $moved);
        } catch (InvalidSettingValueException) {
            // The section validates again (an endpoint or an allow-list may have changed since the target was saved):
            // the transaction was rolled back before any row changed, every file still reads from the active bucket.
            $this->entityManager->resetIfClosed();
            $this->resolver->reset();

            return self::SETTINGS_REFUSED;
        }
        $this->resolver->reset();
        $this->audit->record(StorageAuditEvent::MOVE_SWITCHED, $target->getScope(), $actor, ['from' => $active->getUuid(), 'to' => $target->getUuid(), 'files' => $moved]);

        return self::SWITCHED;
    }

    /**
     * Deletes the owner's retired profiles no file points at any more (their encrypted credentials go with them; the
     * syncs that used them are deleted by the foreign keys). Objects in those buckets are never touched. Returns the
     * number of profiles forgotten. `$all`: every owner's (console).
     */
    public function forgetRetired(?string $scope, ?object $actor, bool $all = false): int
    {
        $manager   = $this->entityManager->manager();
        $forgotten = [];
        foreach ($this->profiles->retiredUnreferenced($scope, $all) as $profile) {
            $forgotten[] = $profile->getUuid();
            // @igor-ignore - Entity manager of the current unit of work (reset between requests by Doctrine).
            $manager->remove($profile);
        }
        if ($forgotten === []) {
            return 0;
        }
        $manager->flush();
        $this->audit->record(StorageAuditEvent::PROFILES_FORGOTTEN, $scope, $actor, ['profiles' => implode(',', $forgotten)]);

        return count($forgotten);
    }

    /**
     * @param array{provider: string, endpoint: string|null, public_endpoint: string|null, region: string, bucket: string, prefix: string, access_key: string, path_style: bool}|null $previous
     */
    private function swap(EntityManagerInterface $manager, string $files, StorageProfile $active, StorageProfile $target, StorageSync $sync, ?InstanceStorageSettings $settings, ?array $previous, ?string $secret, int &$moved): void
    {
        // @igor-ignore - The closure runs synchronously inside the transaction; it is not stored.
        $manager->wrapInTransaction(static function () use ($manager, $files, $active, $target, $sync, $settings, $previous, $secret, &$moved): void {
            if ($settings instanceof InstanceStorageSettings && $previous !== null) {
                // The instance bucket lives in the `storage` section: the section takes the target's connection first
                // (validated again; a refusal changes nothing), the retired row keeps a copy of the old connection
                // (encrypted by DoctrineEncrypt) so its files stay readable, and the target row reads the section.
                $settings->write(self::connectionOf($target), (string) $target->getSecret());
                $active->keepConnection($previous, $secret);
                $target->useInstanceSettings();
            }
            $moved = (int) $manager->getConnection()->executeStatement(
                'UPDATE ' . $files . ' SET profile_id = ? WHERE profile_id = ? AND id <= ?',
                [$target->getId(), $active->getId(), $sync->getCursor()],
                [Types::INTEGER, Types::INTEGER, Types::INTEGER],
            );
            $active->retire();
            $manager->flush();
            $target->activate();
            $manager->flush();
        });
    }

    /**
     * The plain connection fields of a database row, in the shape of the `storage` section.
     *
     * @return array{provider: string, endpoint: string|null, public_endpoint: string|null, region: string, bucket: string, prefix: string, access_key: string, path_style: bool}
     */
    private static function connectionOf(StorageProfile $profile): array
    {
        return [
            'provider'        => $profile->getProvider(),
            'endpoint'        => $profile->getEndpoint(),
            'public_endpoint' => $profile->getPublicEndpoint(),
            'region'          => $profile->getRegion(),
            'bucket'          => $profile->getBucket(),
            'prefix'          => $profile->getPrefix(),
            'access_key'      => $profile->getAccessKey(),
            'path_style'      => $profile->isPathStyle(),
        ];
    }
}
