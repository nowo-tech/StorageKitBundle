<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\EventListener;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\StorageKitBundle\Audit\AuditRecorder;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Settings\StorageSettingsSection;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function implode;

/**
 * Reacts to a change of the `storage` Instance Settings section: drops the memoised profiles and connections (worker
 * safe: the next request reads the new values), forgets the instance row's last test result (it tested the old
 * connection) and records a `profile_saved` audit event with the changed field names (never values).
 *
 * Since 1.5 the test result is forgotten with a DBAL statement: the listener runs inside the host's settings save and
 * never flushes an entity manager (architecture bar rule 6).
 */
final readonly class InstanceSettingsListener
{
    /**
     * @param StorageProfiles $profiles profile resolver (reset, instance row)
     * @param FileStorage $storage connection memo (reset)
     * @param StorageEntityManager $entityManager bundle entity manager
     * @param AuditRecorder $audit audit events
     * @param TokenStorageInterface|null $tokens current user (audit actor)
     */
    public function __construct(
        private StorageProfiles $profiles,
        private FileStorage $storage,
        private StorageEntityManager $entityManager,
        private AuditRecorder $audit,
        private ?TokenStorageInterface $tokens = null,
    ) {
    }

    /**
     * Handles {@see SettingsChangedEvent} for the `storage` section only.
     */
    public function __invoke(SettingsChangedEvent $event): void
    {
        if ($event->section !== StorageSettingsSection::NAME) {
            return;
        }
        $this->profiles->reset();
        $this->storage->reset();
        $active = $this->profiles->activeOf(null);
        if ($active instanceof StorageProfile && $active->isSettingsBacked() && $active->getTestedAt() instanceof DateTimeImmutable) {
            $active->forgetTest();
            // 1.5 (rule 6): a DBAL update of these three columns; the host's settings save is never flushed by us.
            $this->entityManager->update($active, StorageProfile::TEST_FIELDS);
        }
        $this->audit->record(StorageAuditEvent::PROFILE_SAVED, null, $this->tokens?->getToken()?->getUser(), [
            'profile' => $active?->getUuid(),
            'fields'  => implode(',', $event->changedFields),
            'origin'  => $event->origin,
        ]);
    }
}
