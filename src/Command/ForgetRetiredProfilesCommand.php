<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Command;

use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Scope\StorageScope;
use Nowo\StorageKitBundle\Sync\StorageMove;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_map;
use function count;
use function is_string;
use function sprintf;

/**
 * `nowo:storage-kit:forget-retired [--scope=<key>|--all] [--dry-run]` deletes the retired profiles (buckets moved
 * away from) no stored file points at any more, with their encrypted credentials — of the instance by default, of one
 * scope, or of every owner. Objects in those buckets are never deleted. Audited (`profiles_forgotten`).
 */
#[AsCommand(name: self::NAME, description: 'Delete retired storage profiles (and their encrypted credentials) no file uses any more')]
final class ForgetRetiredProfilesCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:storage-kit:forget-retired';

    /**
     * Dependencies.
     */
    public function __construct(
        private readonly StorageProfileRepository $profiles,
        private readonly StorageMove $move,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Scope key (default: the instance)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every owner (the instance and every scope)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be forgotten without deleting it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $scope = $input->getOption('scope');
        $all   = $input->getOption('all') === true;
        if ($scope !== null && (!is_string($scope) || !StorageScope::isValid($scope) || $all)) {
            $io->error('Use either --scope=<key> (1-64 letters, digits, "_", ".", ":" or "-") or --all.');

            return Command::INVALID;
        }
        $candidates = $this->profiles->retiredUnreferenced(is_string($scope) ? $scope : null, $all);
        if ($candidates === []) {
            $io->success('No retired profile to forget.');

            return Command::SUCCESS;
        }
        $io->listing(array_map(static fn (StorageProfile $profile): string => sprintf('%s (%s, bucket "%s")', $profile->getUuid(), $profile->getScope() ?? 'instance', $profile->getBucket()), $candidates));
        if ($input->getOption('dry-run') === true) {
            $io->note(sprintf('Dry run: %d profile(s) would be forgotten.', count($candidates)));

            return Command::SUCCESS;
        }
        $io->success(sprintf('%d profile(s) forgotten.', $this->move->forgetRetired(is_string($scope) ? $scope : null, null, $all)));

        return Command::SUCCESS;
    }
}
