<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Command;

use DateTimeInterface;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\PersonalData\UploaderKeyResolverInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Throwable;

use function array_column;
use function array_flip;
use function ctype_digit;
use function is_callable;
use function is_string;
use function max;
use function min;
use function sprintf;
use function strlen;

/**
 * One-off backfill of `uploaded_by_key` for files uploaded before 1.2.0 (or by a user who had no key then): for each
 * distinct stored user identifier without a key, loads the user through the configured user provider
 * (`personal_data.user_provider`) and stores what the configured {@see UploaderKeyResolverInterface} returns. Exact
 * parity with new uploads, for any id type (integer, UUID, ULID) and any custom resolver.
 *
 * Idempotent (only rows without a key are touched) and batched (`--batch` identifiers per query page; one `UPDATE`
 * per identifier). Identifiers the provider no longer knows, and users without a key, are skipped and keep the
 * identifier-only matching.
 *
 * The user is loaded by the identifier **as it is now**: an identifier given to another person since the upload
 * would stamp the former owner's files with the new holder's key. When the user has a public `getCreatedAt()`
 * returning a date, files created before it are left without key (they cannot be that user's). Otherwise run
 * `--dry-run` first and leave identifiers that were ever reassigned out (`--skip`).
 */
#[AsCommand(name: self::NAME, description: 'Fill the stable uploader key of stored files uploaded before 1.2.0 (GDPR export / erasure after an identifier change)')]
final class BackfillUploaderKeysCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:storage-kit:backfill-uploader-keys';

    /** Default identifiers per batch. */
    public const BATCH = 200;

    /**
     * @param UserProviderInterface<UserInterface>|null $users the provider that loads users by identifier (null: the command refuses to run)
     */
    public function __construct(
        private readonly StorageEntityManager $entityManager,
        private readonly UploaderKeyResolverInterface $keys,
        private readonly ?UserProviderInterface $users = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Identifiers per batch (1-1000)', (string) self::BATCH)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be written, change nothing')
            ->addOption('skip', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'An identifier to leave without key (for example a reassigned e-mail)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->users instanceof UserProviderInterface) {
            $io->error('No user provider: set nowo_storage_kit.personal_data.user_provider (default security.user_providers needs SecurityBundle).');

            return Command::FAILURE;
        }
        $batch = $input->getOption('batch');
        if (!is_string($batch) || !ctype_digit($batch)) {
            $io->error('--batch must be a positive number.');

            return Command::INVALID;
        }
        $batch = min(1000, max(1, (int) $batch));
        $dry   = (bool) $input->getOption('dry-run');
        /** @var list<string> $skip */
        $skip    = (array) $input->getOption('skip');
        $skipSet = array_flip($skip);
        $manager = $this->entityManager->manager();
        $stats   = ['keyed' => 0, 'files' => 0, 'unknown' => 0, 'keyless' => 0, 'skipped' => 0, 'older' => 0];
        $after   = '';
        while (true) {
            /** @var list<string> $identifiers */
            $identifiers = array_column($manager->createQueryBuilder()
                ->select('DISTINCT f.uploadedBy AS id')
                ->from(StoredFile::class, 'f')
                ->andWhere('f.uploadedByKey IS NULL')
                ->andWhere('f.uploadedBy IS NOT NULL')
                ->andWhere('f.uploadedBy > :after')
                ->setParameter('after', $after)
                ->orderBy('f.uploadedBy', 'ASC')
                ->setMaxResults($batch)
                ->getQuery()
                ->getArrayResult(), 'id');
            if ($identifiers === []) {
                break;
            }
            foreach ($identifiers as $identifier) {
                $after = $identifier;
                if (isset($skipSet[$identifier])) {
                    ++$stats['skipped'];

                    continue;
                }
                $user = $this->load($identifier);
                if (!$user instanceof UserInterface) {
                    ++$stats['unknown'];

                    continue;
                }
                $key = $this->keys->keyOf($user);
                if ($key === null || $key === '' || strlen($key) > UploaderKeyResolverInterface::MAX_LENGTH) {
                    ++$stats['keyless'];

                    continue;
                }
                ++$stats['keyed'];
                $since = $this->createdAt($user);
                if ($since instanceof DateTimeInterface) {
                    $stats['older'] += (int) $manager->createQueryBuilder()
                        ->select('COUNT(f.id)')
                        ->from(StoredFile::class, 'f')
                        ->andWhere('f.uploadedBy = :id')
                        ->andWhere('f.uploadedByKey IS NULL')
                        ->andWhere('f.createdAt < :since')
                        ->setParameter('id', $identifier)
                        ->setParameter('since', $since)
                        ->getQuery()
                        ->getSingleScalarResult();
                }
                if ($dry) {
                    $io->writeln(sprintf('%s → %s', $identifier, $key), OutputInterface::VERBOSITY_VERBOSE);

                    continue;
                }
                $update = $manager->createQueryBuilder()
                    ->update(StoredFile::class, 'f')
                    ->set('f.uploadedByKey', ':key')
                    ->andWhere('f.uploadedBy = :id')
                    ->andWhere('f.uploadedByKey IS NULL')
                    ->setParameter('key', $key)
                    ->setParameter('id', $identifier);
                if ($since instanceof DateTimeInterface) {
                    $update->andWhere('f.createdAt >= :since')->setParameter('since', $since);
                }
                $stats['files'] += (int) $update->getQuery()->execute();
            }
        }
        $io->success(sprintf(
            '%sIdentifiers keyed: %d (files: %d); unknown to the provider: %d; users without key: %d; skipped: %d; files older than their user, left without key: %d.',
            $dry ? '[dry run] ' : '',
            $stats['keyed'],
            $stats['files'],
            $stats['unknown'],
            $stats['keyless'],
            $stats['skipped'],
            $stats['older'],
        ));

        return Command::SUCCESS;
    }

    /**
     * The user an identifier names now, or null when the provider does not know it.
     */
    private function load(string $identifier): ?UserInterface
    {
        try {
            return $this->users?->loadUserByIdentifier($identifier);
        } catch (UserNotFoundException) {
            return null;
        }
    }

    /**
     * The user's public `getCreatedAt()` without arguments when it returns a date; null otherwise (or when it throws).
     */
    private function createdAt(UserInterface $user): ?DateTimeInterface
    {
        $getter = [$user, 'getCreatedAt'];
        if (!is_callable($getter)) {
            return null;
        }
        try {
            $since = $getter();
        } catch (Throwable) {
            return null;
        }

        return $since instanceof DateTimeInterface ? $since : null;
    }
}
