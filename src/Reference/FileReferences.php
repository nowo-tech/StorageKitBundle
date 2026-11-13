<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Reference;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Entity\StoredFileReference;
use Nowo\StorageKitBundle\Identifier\ExactIdentifier;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function array_diff;
use function array_diff_key;
use function array_flip;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_scalar;

/**
 * Which files each text points at (`storage_kit_file_reference`, plain SQL: it runs after the flush that saved the
 * text). A file a text starts pointing at stops being an orphan; a file the last text drops becomes one
 * (`orphaned_since`, the retention window of the purge) — never while another text still points at it. An id of no
 * stored file is ignored, and so is one of another file context: a UUID learned elsewhere cannot keep someone else's
 * orphan from the purge.
 *
 * Source ids and context ids are matched byte for byte (REQ-SEC-006, 1.5.3): every read filters with an exact SQL
 * expression ({@see ExactIdentifier::sql()}) and re-checks the rows with `===`, and every delete / update goes by
 * primary key, so the text `abc` never drops, adds or keeps the references of the text `ABC` (nor `abc `, `ábc`) on a
 * case-, accent- or trailing-space-insensitive collation. A source id that is not usable
 * ({@see ExactIdentifier::usable()}, at most {@see self::SOURCE_ID_MAX} characters) or a context outside the context
 * syntax references nothing (logged). While `source_id` still has a folding collation (before the `ALTER` of
 * UPGRADING 1.5.3) its unique key cannot hold two look-alike texts on one file: the second reference is then not
 * written and a warning is logged (fails closed, never retried with the look-alike's row).
 */
final readonly class FileReferences implements FileReferencesInterface
{
    /** Longest source id (`storage_kit_file_reference.source_id`). */
    public const SOURCE_ID_MAX = 64;

    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private StorageEntityManager $entityManager,
        private FileUris $uris,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Stores the files a text points at now — only files of the text's own context.
     *
     * @param array{0: string, 1: string}|null $context the text's file context (null: references nothing new)
     */
    public function sync(string $type, string $id, string $text, ?array $context): void
    {
        if (ExactIdentifier::usable($id, self::SOURCE_ID_MAX) === null) {
            $this->logger->warning('Storage kit: the file references of a {type} text were not stored: its source id is empty, too long, padded or holds control characters.', ['type' => $type]);

            return;
        }
        if ($context !== null && (!FileContexts::isType($context[0]) || !FileContexts::isId($context[1]))) {
            $this->logger->warning('Storage kit: a {type} text reported a malformed file context; it references no new file.', ['type' => $type]);
            $context = null;
        }
        $connection = $this->entityManager->connection();
        $platform   = $connection->getDatabasePlatform();
        $files      = $this->entityManager->table(StoredFile::class);
        $references = $this->entityManager->table(StoredFileReference::class);
        $wanted     = $this->uris->storedIds($text);
        /** @var array<string, array{ref: int, file: int}> $current uuid => reference and file id */
        $current = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT r.id AS ref_id, r.file_id, r.source_type, r.source_id, f.uuid FROM ' . $references . ' r INNER JOIN ' . $files . ' f ON f.id = r.file_id'
            . ' WHERE r.source_type = ? AND r.source_id = ? AND ' . ExactIdentifier::sql($platform, 'r.source_id') . ' = ?',
            [$type, $id, $id],
        ) as $row) {
            if (ExactIdentifier::same($row['source_type'], $type) && ExactIdentifier::same($row['source_id'], $id)) {
                $current[(string) $row['uuid']] = ['ref' => (int) $row['ref_id'], 'file' => (int) $row['file_id']];
            }
        }
        $added   = array_values(array_diff($wanted, array_keys($current)));
        $removed = array_diff_key($current, array_flip($wanted));

        if ($added !== [] && $context !== null) {
            $fileIds = $this->filesOfContext($added, $context[0], $context[1]);
            if ($fileIds !== []) {
                $this->insertReferences($type, $id, $fileIds);
                $connection->executeStatement(
                    'UPDATE ' . $files . ' SET orphaned_since = NULL WHERE id IN (?)',
                    [$fileIds],
                    [ArrayParameterType::INTEGER],
                );
            }
        }
        if ($removed !== []) {
            $connection->executeStatement(
                'DELETE FROM ' . $references . ' WHERE id IN (?)',
                [array_values(array_map(static fn (array $r): int => $r['ref'], $removed))],
                [ArrayParameterType::INTEGER],
            );
            $this->orphanUnreferenced(array_values(array_map(static fn (array $r): int => $r['file'], $removed)), new DateTimeImmutable());
        }
    }

    /**
     * Drops every reference of the given sources (texts that no longer exist), byte for byte.
     *
     * @param list<string> $ids
     */
    public function forget(string $type, array $ids): void
    {
        $ids = array_values(array_unique(array_map(strval(...), $ids)));
        if ($ids === []) {
            return;
        }
        $connection = $this->entityManager->connection();
        $references = $this->entityManager->table(StoredFileReference::class);
        $refIds     = [];
        $fileIds    = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT id, file_id, source_type, source_id FROM ' . $references
            . ' WHERE source_type = ? AND source_id IN (?) AND ' . ExactIdentifier::sql($connection->getDatabasePlatform(), 'source_id') . ' IN (?)',
            [$type, $ids, $ids],
            [Types::STRING, ArrayParameterType::STRING, ArrayParameterType::STRING],
        ) as $row) {
            if (ExactIdentifier::same($row['source_type'], $type) && is_scalar($row['source_id']) && in_array((string) $row['source_id'], $ids, true)) {
                $refIds[]                       = (int) $row['id'];
                $fileIds[(int) $row['file_id']] = true;
            }
        }
        if ($refIds === []) {
            return;
        }
        $connection->executeStatement('DELETE FROM ' . $references . ' WHERE id IN (?)', [$refIds], [ArrayParameterType::INTEGER]);
        $this->orphanUnreferenced(array_keys($fileIds), new DateTimeImmutable());
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

    /**
     * The ids of the files among `$uuids` stored in exactly that context.
     *
     * @param list<string> $uuids
     *
     * @return list<int>
     */
    private function filesOfContext(array $uuids, string $contextType, string $contextId): array
    {
        $connection = $this->entityManager->connection();
        $ids        = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT id, uuid, context_type, context_id FROM ' . $this->entityManager->table(StoredFile::class)
            . ' WHERE uuid IN (?) AND context_type = ? AND context_id = ? AND ' . ExactIdentifier::sql($connection->getDatabasePlatform(), 'context_id') . ' = ?',
            [$uuids, $contextType, $contextId, $contextId],
            [ArrayParameterType::STRING, Types::STRING, Types::STRING, Types::STRING],
        ) as $row) {
            if (is_scalar($row['uuid']) && in_array((string) $row['uuid'], $uuids, true) && ExactIdentifier::same($row['context_type'], $contextType) && ExactIdentifier::same($row['context_id'], $contextId)) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * One statement: the files not referenced by this text yet (a concurrent save adds nothing twice). The `NOT EXISTS`
     * compares `source_id` with the column collation on purpose: while it still folds, it skips a file a look-alike text
     * already holds instead of hitting the folding unique key; fewer rows inserted than asked is logged. A unique
     * violation all the same (two saves at once) is logged and dropped: no statement is retried, nothing reaches the
     * look-alike's row, no entity manager is touched. The statement runs as its own unit
     * ({@see StorageEntityManager::atomically()}): inside a host transaction a savepoint, rolled back on the violation,
     * so the host transaction stays usable (PostgreSQL aborts the whole transaction on a failed statement otherwise).
     *
     * @param list<int> $fileIds
     */
    private function insertReferences(string $type, string $id, array $fileIds): void
    {
        $files      = $this->entityManager->table(StoredFile::class);
        $references = $this->entityManager->table(StoredFileReference::class);
        try {
            $inserted = (int) $this->entityManager->atomically(static fn (Connection $connection): int|string => $connection->executeStatement(
                'INSERT INTO ' . $references . ' (file_id, source_type, source_id, created_at)'
                . ' SELECT f.id, ?, ?, ? FROM ' . $files . ' f WHERE f.id IN (?)'
                . ' AND NOT EXISTS (SELECT 1 FROM ' . $references . ' r WHERE r.file_id = f.id AND r.source_type = ? AND r.source_id = ?)',
                [$type, $id, new DateTimeImmutable(), $fileIds, $type, $id],
                [Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE, ArrayParameterType::INTEGER, Types::STRING, Types::STRING],
            ), 'reference');
        } catch (UniqueConstraintViolationException) {
            $inserted = 0;
        }
        if ($inserted < count($fileIds)) {
            $this->logger->warning('Storage kit: {skipped} file reference(s) of a {type} text skipped: another text with a look-alike id (or a concurrent save) already holds them; run the storage_kit_file_reference ALTER from UPGRADING 1.5.3.', [
                'skipped' => count($fileIds) - $inserted,
                'type'    => $type,
            ]);
        }
    }
}
