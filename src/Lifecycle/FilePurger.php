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
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Reference\FileReferences;
use Nowo\StorageKitBundle\Reference\FileReferenceSourceInterface;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_diff;
use function array_map;
use function array_values;
use function count;
use function end;
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

    private function dropGoneSources(FileReferenceSourceInterface $source): int
    {
        $connection = $this->entityManager->connection();
        $references = $this->entityManager->table(StoredFileReference::class);
        $dropped    = 0;
        $after      = '';
        for ($round = 0; $round < $this->maxBatches; ++$round) {
            /** @var list<string> $ids */
            $ids = $connection->fetchFirstColumn(
                'SELECT DISTINCT source_id FROM ' . $references . ' WHERE source_type = ? AND source_id > ? ORDER BY source_id LIMIT ' . $this->batch,
                [$source->type(), $after],
            );
            if ($ids === []) {
                break;
            }
            $after = end($ids);
            $gone  = array_values(array_diff($ids, $source->existing($ids)));
            $this->references->forget($source->type(), $gone);
            $dropped += count($gone);
        }

        return $dropped;
    }

    private function dropGoneContexts(DateTimeImmutable $now): int
    {
        $connection = $this->entityManager->connection();
        $files      = $this->entityManager->table(StoredFile::class);
        $references = $this->entityManager->table(StoredFileReference::class);
        $dropped    = 0;
        // A context type this host does not register (any more) is left alone.
        foreach ($this->contexts->types() as $type) {
            $context = $this->contexts->get($type);
            $after   = '';
            for ($round = 0; $context instanceof FileContextInterface && $round < $this->maxBatches; ++$round) {
                /** @var list<string> $ids */
                $ids = $connection->fetchFirstColumn(
                    'SELECT DISTINCT context_id FROM ' . $files . ' WHERE context_type = ? AND context_id > ? ORDER BY context_id LIMIT ' . $this->batch,
                    [$type, $after],
                );
                if ($ids === []) {
                    break;
                }
                $after = end($ids);
                $gone  = array_values(array_diff($ids, $context->existing($ids)));
                if ($gone === []) {
                    continue;
                }
                /** @var list<int|string> $fileIds */
                $fileIds = $connection->fetchFirstColumn('SELECT id FROM ' . $files . ' WHERE context_type = ? AND context_id IN (?)', [$type, $gone], [Types::STRING, ArrayParameterType::STRING]);
                $fileIds = array_map(intval(...), $fileIds);
                $connection->executeStatement('DELETE FROM ' . $references . ' WHERE file_id IN (?)', [$fileIds], [ArrayParameterType::INTEGER]);
                $this->references->orphanUnreferenced($fileIds, $now);
                $dropped += count($fileIds);
            }
        }

        return $dropped;
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
