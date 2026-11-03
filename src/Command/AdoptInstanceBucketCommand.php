<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Command;

use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\StorageKitBundle\Audit\AuditRecorder;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Settings\InstanceStorageSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_keys;
use function implode;
use function sprintf;

/**
 * `nowo:storage-kit:adopt-instance-bucket` moves the connection of the instance's active database row (rows imported
 * from an application's own storage tables) into the `storage` Instance Settings section, then turns that same row
 * into the settings-backed active profile, so every file keeps pointing at it. Idempotent: an instance already
 * settings-backed (or without an active row) is left alone. A section that already has a provider is not overwritten
 * unless `--force`. `--dry-run` only reports. Never prints a credential.
 */
#[AsCommand(name: self::NAME, description: 'Move the instance bucket kept in the profile table into the "storage" Instance Settings section')]
final class AdoptInstanceBucketCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:storage-kit:adopt-instance-bucket';

    /**
     * Dependencies.
     */
    public function __construct(
        private readonly StorageProfileRepository $profiles,
        private readonly InstanceStorageSettings $settings,
        private readonly StorageProfiles $resolver,
        private readonly StorageEntityManager $entityManager,
        private readonly AuditRecorder $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be adopted without changing anything')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite a "storage" section that already has a provider');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $profile = $this->profiles->activeOf(null);
        if (!$profile instanceof StorageProfile || $profile->getSource() !== StorageProfile::SOURCE_DATABASE) {
            $io->success($profile instanceof StorageProfile && $profile->isSettingsBacked() ? 'The instance bucket is already in the "storage" section.' : 'There is no instance bucket in the profile table to adopt.');

            return Command::SUCCESS;
        }
        if (!$profile->isComplete()) {
            $io->error(sprintf('The instance profile %s is incomplete (bucket, access key, secret or endpoint missing): fix it before adopting it.', $profile->getUuid()));

            return Command::FAILURE;
        }
        if ($this->settings->provider() !== null && $input->getOption('force') !== true) {
            $io->error('The "storage" section already has a provider: run with --force to overwrite it.');

            return Command::INVALID;
        }
        $io->text(sprintf('Instance profile %s: %s bucket "%s".', $profile->getUuid(), $profile->getProvider(), $profile->getBucket()));
        if ($input->getOption('dry-run') === true) {
            $io->note('Dry run: nothing was changed.');

            return Command::SUCCESS;
        }
        $connection = [
            'provider'        => $profile->getProvider(),
            'endpoint'        => $profile->getEndpoint(),
            'public_endpoint' => $profile->getPublicEndpoint(),
            'region'          => $profile->getRegion(),
            'bucket'          => $profile->getBucket(),
            'prefix'          => $profile->getPrefix(),
            'access_key'      => $profile->getAccessKey(),
            'path_style'      => $profile->isPathStyle(),
        ];
        $secret   = (string) $profile->getSecret();
        $manager  = $this->entityManager->manager();
        $settings = $this->settings;
        try {
            // @igor-ignore - The closure runs synchronously inside the transaction; it is not stored.
            $manager->wrapInTransaction(static function () use ($manager, $settings, $profile, $connection, $secret): void {
                $settings->write($connection, $secret);
                $profile->useInstanceSettings();
                $manager->flush();
            });
        } catch (InvalidSettingValueException $e) {
            $io->error(sprintf('The "storage" section refused the connection (fields: %s). Nothing was changed.', implode(', ', array_keys($e->violations))));

            return Command::FAILURE;
        }
        $this->resolver->reset();
        $this->audit->record(StorageAuditEvent::PROFILE_SAVED, null, null, ['profile' => $profile->getUuid(), 'fields' => 'adopted']);
        $io->success('The instance bucket is now edited in the "storage" Instance Settings section.');

        return Command::SUCCESS;
    }
}
