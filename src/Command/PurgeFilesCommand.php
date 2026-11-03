<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Command;

use DateTimeImmutable;
use Nowo\StorageKitBundle\Lifecycle\FilePurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_numeric;
use function max;
use function min;
use function sprintf;

/**
 * Reference-aware purge (REQ-MEDIA-005): marks files no text points at as orphans and deletes orphans older than the
 * retention. Schedule it nightly (cron, Scheduler).
 */
#[AsCommand(name: self::NAME, description: 'Mark stored files no text points at as orphans and delete orphans older than the retention')]
final class PurgeFilesCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:storage-kit:purge';

    /** Longest retention accepted on the command line. */
    public const MAX_DAYS = 3650;

    /**
     * @param int $retentionDays `purge.retention_days`
     */
    public function __construct(
        private readonly FilePurger $purger,
        private readonly int $retentionDays = FilePurger::RETENTION_DAYS,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Days an orphan is kept (default: purge.retention_days)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $days = $input->getOption('days');
        if ($days !== null && !is_numeric($days)) {
            $io->error('--days must be a number of days.');

            return Command::INVALID;
        }
        $stats = $this->purger->run(new DateTimeImmutable(), $days === null ? $this->retentionDays : min(self::MAX_DAYS, max(0, (int) $days)));
        $io->success(sprintf(
            'References of deleted texts: %d; files of deleted contexts: %d; new orphans: %d; purged: %d; failed: %d.',
            $stats['references'],
            $stats['contexts'],
            $stats['orphaned'],
            $stats['purged'],
            $stats['failed'],
        ));

        return $stats['failed'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
