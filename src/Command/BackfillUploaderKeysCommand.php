<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Command;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\PersonalData\UploaderIdentity;
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
use function array_key_exists;
use function assert;
use function ctype_digit;
use function is_array;
use function is_callable;
use function is_scalar;
use function is_string;
use function max;
use function min;
use function sprintf;
use function strlen;
use function substr;

/**
 * One-off backfill of `uploaded_by_key` for files uploaded before 1.2.0 (or by a user who had no key then): for each
 * distinct stored user identifier without a key, loads the user through the configured user provider
 * (`personal_data.user_provider`) and stores what the configured {@see UploaderKeyResolverInterface} returns. Exact
 * parity with new uploads, for any id type (integer, UUID, ULID) and any custom resolver.
 *
 * Idempotent (only rows without a key are touched) and batched (`--batch` identifiers per query page; rows keyed by
 * id, {@see self::ROWS} per `UPDATE`). Identifiers the provider no longer knows, and users without a key, are skipped
 * and keep the identifier-only matching.
 *
 * Exact identifiers (1.5.2, house rule LQ): rows are grouped by their byte-exact identifier, never by what the column
 * collation calls equal (`alice`, `Alice`, `álice`, `alice ` are four identifiers), and a user the provider returns
 * with another identifier than the one asked for (a provider reading a case-insensitive column) is not used.
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

    /** Rows read per query while keying one identifier (1.5.2). */
    public const ROWS = 1000;

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
        $stats   = ['keyed' => 0, 'files' => 0, 'unknown' => 0, 'keyless' => 0, 'skipped' => 0, 'older' => 0, 'mismatched' => 0];
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
                // 1.5.2 (house rule LQ): on a case-, accent- or trailing-space-insensitive collation DISTINCT returns one
                // identifier of a family of look-alikes (`alice`, `Alice`, `álice`, `alice `) and `> :after` skips the
                // others; the family's rows are read once and every exact identifier among them is resolved on its own.
                $this->backfillFamily($manager, $identifier, $skipSet, $dry, $io, $stats);
            }
        }
        $io->success(sprintf(
            '%sIdentifiers keyed: %d (files: %d); unknown to the provider: %d; users without key: %d; skipped: %d; files older than their user, left without key: %d.%s',
            $dry ? '[dry run] ' : '',
            $stats['keyed'],
            $stats['files'],
            $stats['unknown'],
            $stats['keyless'],
            $stats['skipped'],
            $stats['older'],
            $stats['mismatched'] > 0 ? sprintf(' Loaded by the provider under another identifier, left without key: %d.', $stats['mismatched']) : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * Keys the key-less rows whose identifier equals `$identifier` in SQL, grouped by their exact (`===`) identifier:
     * each group is keyed only with the key of the user its own identifier loads, by row id.
     *
     * @param array<array-key, int> $skipSet
     * @param array{keyed: int, files: int, unknown: int, keyless: int, skipped: int, older: int, mismatched: int} $stats
     */
    private function backfillFamily(EntityManagerInterface $manager, string $identifier, array $skipSet, bool $dry, SymfonyStyle $io, array &$stats): void
    {
        /** @var array<string, array{key: string, since: DateTimeInterface|null}|null> $resolved */
        $resolved = [];
        $afterId  = 0;
        while (true) {
            $rows = $manager->createQueryBuilder()
                ->select('f.id AS id', 'f.uploadedBy AS uploadedBy')
                ->from(StoredFile::class, 'f')
                ->andWhere('f.uploadedBy = :id')
                ->andWhere('f.uploadedByKey IS NULL')
                ->andWhere('f.id > :after')
                ->setParameter('id', $identifier)
                ->setParameter('after', $afterId)
                ->orderBy('f.id', 'ASC')
                ->setMaxResults(self::ROWS)
                ->getQuery()
                ->getScalarResult();
            if ($rows === []) {
                return;
            }
            /** @var array<string, list<int>> $groups */
            $groups = [];
            foreach ($rows as $row) {
                assert(is_array($row) && is_scalar($row['id']) && is_scalar($row['uploadedBy']));
                $afterId                            = (int) $row['id'];
                $groups['=' . $row['uploadedBy']][] = $afterId; // prefixed: a numeric identifier stays a string key
            }
            foreach ($groups as $exact => $ids) {
                $exact = substr($exact, 1);
                if (!array_key_exists($exact, $resolved)) {
                    $resolved[$exact] = $this->resolve($exact, $skipSet, $dry, $io, $stats);
                }
                $target = $resolved[$exact];
                if ($target === null) {
                    continue;
                }
                $since = $target['since'];
                if ($since instanceof DateTimeInterface) {
                    $stats['older'] += (int) $manager->createQueryBuilder()
                        ->select('COUNT(f.id)')
                        ->from(StoredFile::class, 'f')
                        ->andWhere('f.id IN (:ids)')
                        ->andWhere('f.createdAt < :since')
                        ->setParameter('ids', $ids)
                        ->setParameter('since', $since)
                        ->getQuery()
                        ->getSingleScalarResult();
                }
                if ($dry) {
                    continue;
                }
                $update = $manager->createQueryBuilder()
                    ->update(StoredFile::class, 'f')
                    ->set('f.uploadedByKey', ':key')
                    ->andWhere('f.id IN (:ids)')
                    ->andWhere('f.uploadedByKey IS NULL')
                    ->setParameter('key', $target['key'])
                    ->setParameter('ids', $ids);
                if ($since instanceof DateTimeInterface) {
                    $update->andWhere('f.createdAt >= :since')->setParameter('since', $since);
                }
                $stats['files'] += (int) $update->getQuery()->execute();
            }
        }
    }

    /**
     * The key (and the user's creation date) the rows stored under exactly `$identifier` get, or null to leave them
     * without key (skipped, unknown to the provider, loaded as another identifier, or a user without a usable key).
     *
     * @param array<array-key, int> $skipSet
     * @param array{keyed: int, files: int, unknown: int, keyless: int, skipped: int, older: int, mismatched: int} $stats
     *
     * @return array{key: string, since: DateTimeInterface|null}|null
     */
    private function resolve(string $identifier, array $skipSet, bool $dry, SymfonyStyle $io, array &$stats): ?array
    {
        if (isset($skipSet[$identifier])) {
            ++$stats['skipped'];

            return null;
        }
        $user = $this->load($identifier);
        if (!$user instanceof UserInterface) {
            ++$stats['unknown'];

            return null;
        }
        // A provider that looks users up on a case- / accent-insensitive column loads `alice` for `Alice` (1.5.2).
        if ($user->getUserIdentifier() !== $identifier) {
            ++$stats['mismatched'];

            return null;
        }
        $key = $this->keys->keyOf($user);
        if ($key === null || strlen($key) > UploaderKeyResolverInterface::MAX_LENGTH || UploaderIdentity::storable($key) === null) {
            ++$stats['keyless'];

            return null;
        }
        ++$stats['keyed'];
        if ($dry) {
            $io->writeln(sprintf('%s → %s', $identifier, $key), OutputInterface::VERBOSITY_VERBOSE);
        }

        return ['key' => $key, 'since' => $this->createdAt($user)];
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
