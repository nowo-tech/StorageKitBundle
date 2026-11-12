<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Reference;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Entity\StoredFileReference;

use function array_diff;
use function array_diff_key;
use function array_flip;
use function array_keys;
use function array_map;
use function array_values;

/**
 * Which files each text points at (`storage_kit_file_reference`, plain SQL: it runs after the flush that saved the
 * text). A file a text starts pointing at stops being an orphan; a file the last text drops becomes one
 * (`orphaned_since`, the retention window of the purge) — never while another text still points at it. An id of no
 * stored file is ignored, and so is one of another file context: a UUID learned elsewhere cannot keep someone else's
 * orphan from the purge.
 */
final readonly class FileReferences implements FileReferencesInterface
{
    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private StorageEntityManager $entityManager,
        private FileUris $uris,
    ) {
    }

    /**
     * Stores the files a text points at now — only files of the text's own context.
     *
     * @param array{0: string, 1: string}|null $context the text's file context (null: references nothing new)
     */
    public function sync(string $type, string $id, string $text, ?array $context): void
    {
        $connection = $this->entityManager->connection();
        $files      = $this->entityManager->table(StoredFile::class);
        $references = $this->entityManager->table(StoredFileReference::class);
        $wanted     = $this->uris->storedIds($text);
        /** @var array<string, int|string> $current uuid => file id */
        $current = $connection->fetchAllKeyValue(
            'SELECT f.uuid, f.id FROM ' . $references . ' r INNER JOIN ' . $files . ' f ON f.id = r.file_id WHERE r.source_type = ? AND r.source_id = ?',
            [$type, $id],
        );
        $added   = array_values(array_diff($wanted, array_keys($current)));
        $removed = array_values(array_map(intval(...), array_diff_key($current, array_flip($wanted))));

        if ($added !== [] && $context !== null) {
            // One statement: the files of the text's context not referenced by this text yet (a concurrent save adds
            // nothing twice).
            $connection->executeStatement(
                'INSERT INTO ' . $references . ' (file_id, source_type, source_id, created_at)'
                . ' SELECT f.id, ?, ?, ? FROM ' . $files . ' f WHERE f.uuid IN (?) AND f.context_type = ? AND f.context_id = ?'
                . ' AND NOT EXISTS (SELECT 1 FROM ' . $references . ' r WHERE r.file_id = f.id AND r.source_type = ? AND r.source_id = ?)',
                [$type, $id, new DateTimeImmutable(), $added, $context[0], $context[1], $type, $id],
                [Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE, ArrayParameterType::STRING, Types::STRING, Types::STRING, Types::STRING, Types::STRING],
            );
            $connection->executeStatement(
                'UPDATE ' . $files . ' SET orphaned_since = NULL WHERE uuid IN (?) AND context_type = ? AND context_id = ?',
                [$added, $context[0], $context[1]],
                [ArrayParameterType::STRING, Types::STRING, Types::STRING],
            );
        }
        if ($removed !== []) {
            $connection->executeStatement(
                'DELETE FROM ' . $references . ' WHERE source_type = ? AND source_id = ? AND file_id IN (?)',
                [$type, $id, $removed],
                [Types::STRING, Types::STRING, ArrayParameterType::INTEGER],
            );
            $this->orphanUnreferenced($removed, new DateTimeImmutable());
        }
    }

    /**
     * Drops every reference of the given sources (texts that no longer exist).
     *
     * @param list<string> $ids
     */
    public function forget(string $type, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $connection = $this->entityManager->connection();
        $references = $this->entityManager->table(StoredFileReference::class);
        /** @var list<int|string> $fileIds */
        $fileIds = $connection->fetchFirstColumn(
            'SELECT DISTINCT file_id FROM ' . $references . ' WHERE source_type = ? AND source_id IN (?)',
            [$type, $ids],
            [Types::STRING, ArrayParameterType::STRING],
        );
        $connection->executeStatement(
            'DELETE FROM ' . $references . ' WHERE source_type = ? AND source_id IN (?)',
            [$type, $ids],
            [Types::STRING, ArrayParameterType::STRING],
        );
        $this->orphanUnreferenced(array_map(intval(...), $fileIds), new DateTimeImmutable());
    }

    /**
     * Marks the files no text points at any more (the window starts once: an orphan keeps its date).
     *
     * @param list<int> $fileIds
     */
    public function orphanUnreferenced(array $fileIds, DateTimeImmutable $now): int
    {
        if ($fileIds === []) {
            return 0;
        }
        $files      = $this->entityManager->table(StoredFile::class);
        $references = $this->entityManager->table(StoredFileReference::class);

        return (int) $this->entityManager->connection()->executeStatement(
            'UPDATE ' . $files . ' SET orphaned_since = ? WHERE id IN (?) AND orphaned_since IS NULL'
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $references . ' r WHERE r.file_id = ' . $files . '.id)',
            [$now, $fileIds],
            [Types::DATETIME_IMMUTABLE, ArrayParameterType::INTEGER],
        );
    }
}
