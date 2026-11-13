<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Lifecycle;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Nowo\StorageKitBundle\Audit\AuditRecorder;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Context\FileContextInterface;
use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Entity\StoredFileReference;
use Nowo\StorageKitBundle\Identifier\ExactIdentifier;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Reference\FileReferences;
use Nowo\StorageKitBundle\Reference\FileReferenceSourceInterface;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_diff;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function end;
use function in_array;
use function is_scalar;
use function max;

/**
 * Reference-aware purge (REQ-MEDIA-005, `nowo:storage-kit:purge`, schedule it nightly), in keyset batches of
 * `purge.batch_size`:
 *
 * 1. references of texts that no longer exist (an SQL cascade deleted them: no Doctrine event) are dropped;
 * 2. files whose context no longer exists lose their references;
 * 3. files no text points at become orphans (`orphaned_since` = now; an orphan keeps its first date);
 * 4. orphans older than the retention are deleted: their objects from their own profile's bucket, then the row. A
 *    bucket that refuses keeps the row for the next run. A file a text points at again is never deleted: each batch
 *    re-checks that no reference appeared in between.
 *
 * Every step is bounded: at most `purge.max_batches` batches per step and run, so a huge backlog is spread over runs.
 * Steps 1 and 2 compare source / context ids byte for byte (REQ-SEC-006, 1.5.3): `abc` and `ABC` are two texts / two
 * contexts even where the column still has a folding collation.
 */
final readonly class FilePurger
{
    /** Default `purge.retention_days`. */
    public const RETENTION_DAYS = 7;

    /** Default `purge.batch_size`. */
    public const BATCH = 500;

    /** Default `purge.max_batches`. */
    public const MAX_BATCHES = 200;

    /** @var list<FileReferenceSourceInterface> */
    private array $sources;

    /**
     * @param iterable<FileReferenceSourceInterface> $sources
     */
    public function __construct(
        private StorageEntityManager $entityManager,
        private FileReferences $references,
        private FileContexts $contexts,
        private StorageProfiles $profiles,
        private FileStorage $storage,
        private AuditRecorder $audit,
        iterable $sources = [],
        private int $batch = self::BATCH,
        private int $maxBatches = self::MAX_BATCHES,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->sources = array_values([...$sources]);
    }

    /**
     * Runs the four steps.
     *
     * @return array{references: int, contexts: int, orphaned: int, purged: int, failed: int}
     */
    public function run(DateTimeImmutable $now, int $days = self::RETENTION_DAYS): array
    {
        $stats = ['references' => 0, 'contexts' => 0, 'orphaned' => 0, 'purged' => 0, 'failed' => 0];
        foreach ($this->sources as $source) {
            $stats['references'] += $this->dropGoneSources($source);
        }
        $stats['contexts']                   = $this->dropGoneContexts($now);
        $stats['orphaned']                   = $this->markOrphans($now);
        [$stats['purged'], $stats['failed']] = $this->purge($now->modify('-' . max(0, $days) . ' days'));
        $this->audit->record(StorageAuditEvent::FILES_PURGED, null, null, $stats);

        return $stats;
    }

    private function markOrphans(DateTimeImmutable $now): int
    {
        $connection   = $this->entityManager->connection();
        $files        = $this->entityManager->table(StoredFile::class);
        $references   = $this->entityManager->table(StoredFileReference::class);
        $unreferenced = ' AND NOT EXISTS (SELECT 1 FROM ' . $references . ' r WHERE r.file_id = ' . $files . '.id)';
        $orphaned     = 0;
        $after        = 0;
        for ($round = 0; $round < $this->maxBatches; ++$round) {
            /** @var list<int|string> $ids */
            $ids = $connection->fetchFirstColumn(
                'SELECT id FROM ' . $files . ' WHERE id > ? AND orphaned_since IS NULL' . $unreferenced . ' ORDER BY id LIMIT ' . $this->batch,
                [$after],
            );
            if ($ids === []) {
                break;
            }
            $after = (int) end($ids);
            $orphaned += (int) $connection->executeStatement(
                'UPDATE ' . $files . ' SET orphaned_since = ? WHERE id IN (?) AND orphaned_since IS NULL' . $unreferenced,
                [$now, array_map(intval(...), $ids)],
                [Types::DATETIME_IMMUTABLE, ArrayParameterType::INTEGER],
            );
        }

        return $orphaned;
    }

    /**
     * Step 1. The keyset walks the column's own order (its index), one representative per look-alike family; every
     * exact spelling of the batch's families is then read with an exact SQL expression (1.5.3), so `abc` and `ABC` are
     * two texts even where `source_id` still has a folding collation (a `DISTINCT` alone would merge them, and the
     * keyset would skip the second one).
     */
    private function dropGoneSources(FileReferenceSourceInterface $source): int
    {
        $connection = $this->entityManager->connection();
        $references = $this->entityManager->table(StoredFileReference::class);
        $type       = $source->type();
        $dropped    = 0;
        $after      = '';
        for ($round = 0; $round < $this->maxBatches; ++$round) {
            /** @var list<string> $families */
            $families = $connection->fetchFirstColumn(
                'SELECT DISTINCT source_id FROM ' . $references . ' WHERE source_type = ? AND source_id > ? ORDER BY source_id LIMIT ' . $this->batch,
                [$type, $after],
            );
            if ($families === []) {
                break;
            }
            $after = end($families);
            $ids   = $this->spellings($references, 'source_type', 'source_id', $type, $families);
            $gone  = array_values(array_diff($ids, $this->exactly($ids, $source->existing($ids))));
            $this->references->forget($type, $gone);
            $dropped += count($gone);
        }

        return $dropped;
    }

    /**
     * Step 2, walked like step 1 (1.5.3): the files of a gone context are read by its exact spelling, so deleting the
     * context `ABC` never orphans the files of `abc` on a folding collation.
     */
    private function dropGoneContexts(DateTimeImmutable $now): int
    {
        $connection = $this->entityManager->connection();
        $files      = $this->entityManager->table(StoredFile::class);
        $references = $this->entityManager->table(StoredFileReference::class);
        $exact      = ExactIdentifier::sql($connection->getDatabasePlatform(), 'context_id');
        $dropped    = 0;
        // A context type this host does not register (any more) is left alone.
        foreach ($this->contexts->types() as $type) {
            $context = $this->contexts->get($type);
            $after   = '';
            for ($round = 0; $context instanceof FileContextInterface && $round < $this->maxBatches; ++$round) {
                /** @var list<string> $families */
                $families = $connection->fetchFirstColumn(
                    'SELECT DISTINCT context_id FROM ' . $files . ' WHERE context_type = ? AND context_id > ? ORDER BY context_id LIMIT ' . $this->batch,
                    [$type, $after],
                );
                if ($families === []) {
                    break;
                }
                $after = end($families);
                $ids   = $this->spellings($files, 'context_type', 'context_id', $type, $families);
                $gone  = array_values(array_diff($ids, $this->exactly($ids, $context->existing($ids))));
                if ($gone === []) {
                    continue;
                }
                $fileIds = [];
                foreach ($connection->fetchAllAssociative(
                    'SELECT id, context_type, context_id FROM ' . $files . ' WHERE context_type = ? AND context_id IN (?) AND ' . $exact . ' IN (?)',
                    [$type, $gone, $gone],
                    [Types::STRING, ArrayParameterType::STRING, ArrayParameterType::STRING],
                ) as $row) {
                    if (ExactIdentifier::same($row['context_type'], $type) && is_scalar($row['context_id']) && in_array((string) $row['context_id'], $gone, true)) {
                        $fileIds[] = (int) $row['id'];
                    }
                }
                $connection->executeStatement('DELETE FROM ' . $references . ' WHERE file_id IN (?)', [$fileIds], [ArrayParameterType::INTEGER]);
                $this->references->orphanUnreferenced($fileIds, $now);
                $dropped += count($fileIds);
            }
        }

        return $dropped;
    }

    /**
     * Every exact spelling stored for the given representatives (the column's collation matches the whole family).
     *
     * @param list<string> $families
     *
     * @return list<string>
     */
    private function spellings(string $table, string $typeColumn, string $idColumn, string $type, array $families): array
    {
        $connection = $this->entityManager->connection();
        $exact      = ExactIdentifier::sql($connection->getDatabasePlatform(), $idColumn);
        $ids        = [];
        foreach ($connection->fetchFirstColumn(
            'SELECT DISTINCT ' . $exact . ' FROM ' . $table . ' WHERE ' . $typeColumn . ' = ? AND ' . $idColumn . ' IN (?)',
            [$type, $families],
            [Types::STRING, ArrayParameterType::STRING],
        ) as $id) {
            if (is_scalar($id)) {
                $ids[] = (string) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The ids a host `existing()` returned that are exactly among the asked ones (a host lookup on a folding collation
     * may answer `abc` for `ABC`: that does not make `ABC` exist).
     *
     * @param list<string> $asked
     * @param list<string> $existing
     *
     * @return list<string>
     */
    private function exactly(array $asked, array $existing): array
    {
        return array_values(array_filter(array_map(strval(...), $existing), static fn (string $id): bool => in_array($id, $asked, true)));
    }

    /**
     * @return array{0: int, 1: int} purged, failed
     */
    private function purge(DateTimeImmutable $cutoff): array
    {
        $manager    = $this->entityManager->manager();
        $connection = $manager->getConnection();
        $files      = $this->entityManager->table(StoredFile::class);
        $references = $this->entityManager->table(StoredFileReference::class);
        $purged     = 0;
        $failed     = 0;
        $after      = 0;
        for ($round = 0; $round < $this->maxBatches; ++$round) {
            /** @var list<int|string> $ids */
            $ids = $connection->fetchFirstColumn(
                'SELECT f.id FROM ' . $files . ' f WHERE f.id > ? AND f.orphaned_since IS NOT NULL AND f.orphaned_since <= ?'
                . ' AND NOT EXISTS (SELECT 1 FROM ' . $references . ' r WHERE r.file_id = f.id) ORDER BY f.id LIMIT ' . $this->batch,
                [$after, $cutoff],
                [Types::INTEGER, Types::DATETIME_IMMUTABLE],
            );
            if ($ids === []) {
                break;
            }
            $after = (int) end($ids);
            /** @var list<StoredFile> $batch */
            $batch = $manager->createQueryBuilder()
                ->select('f', 'p')
                ->from(StoredFile::class, 'f')
                ->innerJoin('f.profile', 'p')
                ->andWhere('f.id IN (:ids)')
                ->setParameter('ids', array_map(intval(...), $ids))
                ->getQuery()
                ->getResult();
            foreach ($batch as $file) {
                if ($this->deleteObjects($file)) {
                    // @igor-ignore - Entity manager of the current unit of work (reset between requests by Doctrine).
                    $manager->remove($file);
                    ++$purged;
                } else {
                    ++$failed;
                }
            }
            $manager->flush();
            // Kept files leave the unit of work (a long purge does not grow it); removed ones already have.
            foreach ($batch as $file) {
                if ($manager->contains($file)) {
                    $manager->detach($file);
                }
            }
        }

        return [$purged, $failed];
    }

    private function deleteObjects(StoredFile $file): bool
    {
        $settings = $this->profiles->settingsOf($file->getProfile());
        if (!$settings instanceof S3Settings) {
            $this->logger->warning('Could not purge the objects of stored file {uuid}: its profile is not usable.', ['uuid' => $file->getUuid()]);

            return false;
        }
        try {
            $connection = $this->storage->connection($settings);
            foreach ($file->objectKeys() as $key) {
                $connection->delete($key);
            }

            return true;
        } catch (Throwable) {
            $this->logger->warning('Could not purge the objects of stored file {uuid}.', ['uuid' => $file->getUuid()]);

            return false;
        }
    }
}
