<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Command;

use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Sync\StorageSyncer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function max;
use function sprintf;
use function ucfirst;

/**
 * `nowo:storage-kit:sync <from> <to>` copies every file of one storage profile to another (UUIDs shown on the Move
 * page), verified by SHA-256, resuming the pair's last sync (the same state as the background job of the Move page);
 * `--check` only compares: objects of the source's files missing from the target, and objects under the target's
 * prefix no file knows (`<from>` = `<to>` checks one bucket). *Switch* stays on the Move page.
 */
#[AsCommand(name: self::NAME, description: 'Copy the files of one storage profile to another, verified and resumable; --check compares them')]
final class StorageSyncCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:storage-kit:sync';

    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private readonly StorageProfileRepository $profiles,
        private readonly StorageSyncer $syncer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('from', InputArgument::REQUIRED, 'UUID of the storage profile to copy from')
            ->addArgument('to', InputArgument::REQUIRED, 'UUID of the storage profile to copy to')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Compare the buckets and the database without copying');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $source = $this->profiles->findOneByUuid((string) $input->getArgument('from'));
        $target = $this->profiles->findOneByUuid((string) $input->getArgument('to'));
        if (!$source instanceof StorageProfile || !$target instanceof StorageProfile) {
            $io->error('Unknown storage profile (use the UUIDs shown on the Move page).');

            return Command::INVALID;
        }
        if ($input->getOption('check') === true) {
            return $this->check($io, $source, $target);
        }
        if ($source === $target) {
            $io->error('A profile cannot be synced to itself.');

            return Command::INVALID;
        }
        $sync = $this->syncer->open($source, $target);
        $bar  = $io->createProgressBar(max(1, $this->syncer->remaining($sync)));
        // @igor-ignore - The closure runs synchronously inside run(); it is not stored on the service.
        $ran = $this->syncer->run($sync, null, static function () use ($bar): void {
            $bar->advance();
        });
        $bar->finish();
        $io->newLine(2);
        if (!$ran) {
            $io->error('This sync is running elsewhere (started from the Move page): wait for it or cancel it there.');

            return Command::FAILURE;
        }
        if (!$sync->isCompleted()) {
            $io->error(sprintf('Sync %s: %s after %d of %d files.%s', $sync->getStatus(), $sync->getError() ?? '', $sync->getProcessed(), $sync->getTotal(), $sync->getStatus() === StorageSync::FAILED ? ' Run the command again to resume from that file.' : ''));

            return Command::FAILURE;
        }
        $io->success(sprintf('%d files verified (%d objects copied, %d already there).', $sync->getProcessed(), $sync->getCopiedObjects(), $sync->getSkippedObjects()));

        return Command::SUCCESS;
    }

    private function check(SymfonyStyle $io, StorageProfile $source, StorageProfile $target): int
    {
        $report = $this->syncer->check($source, $target);
        $io->writeln(sprintf('Objects expected: %d; missing: %d; unknown: %d.%s', $report['expected'], $report['missing_count'], $report['unknown_count'], $report['truncated'] ? ' (bucket listing truncated)' : ''));
        foreach (['missing' => $report['missing'], 'unknown' => $report['unknown']] as $label => $keys) {
            if ($keys !== []) {
                $io->section(ucfirst($label) . (count($keys) < $report[$label . '_count'] ? sprintf(' (first %d)', count($keys)) : ''));
                $io->listing($keys);
            }
        }
        if ($report['missing_count'] > 0) {
            $io->error('Some objects are missing from the target bucket.');

            return Command::FAILURE;
        }
        $io->success('Every object of the source is in the target bucket.');

        return Command::SUCCESS;
    }
}
