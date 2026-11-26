<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Lifecycle;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Entity\StoredFileReference;
use Nowo\StorageKitBundle\Upload\FileVariantWriterInterface;

use function is_array;
use function is_string;
use function json_decode;

/**
 * The two units around the deletion of a stored file's objects (2.2, audit ZCK-SK-M1), shared by {@see FileRemover}
 * and {@see FilePurger}:
 *
 * 1. {@see self::claim()}: one short unit that locks the file row (`SELECT … FOR UPDATE` on MySQL / MariaDB /
 *    PostgreSQL), drops its references, expires it and returns every object key it holds **under that lock**. From
 *    then on {@see FileVariantWriterInterface::putVariant()} refuses the file (its locking read re-checks the expiry),
 *    so no object can be attached that the list misses.
 * 2. The caller deletes the objects with no lock held.
 * 3. {@see self::delete()}: the row goes (only while it is still expired). When the bucket refused, the expired row
 *    stays for the next purge, which retries every key on it.
 *
 * Stateless (static methods only).
 *
 * @internal
 */
final class FileClaims
{
    /**
     * Locks, un-references and expires the row `$id`; returns its object keys, or null when the row is gone (or, with
     * `$orphanedUntil`, no longer an unreferenced orphan of at most that date).
     *
     * @return list<string>|null
     */
    public static function claim(StorageEntityManager $entityManager, int $id, ?DateTimeImmutable $orphanedUntil = null): ?array
    {
        $files      = $entityManager->table(StoredFile::class);
        $references = $entityManager->table(StoredFileReference::class);
        $platform   = $entityManager->connection()->getDatabasePlatform();
        $lock       = $platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform ? ' FOR UPDATE' : '';

        // @igor-ignore - The closure runs synchronously inside atomically(); nothing is kept.
        return $entityManager->atomically(static function (Connection $connection) use ($files, $references, $lock, $id, $orphanedUntil): ?array {
            $sql    = 'SELECT storage_key, variants FROM ' . $files . ' WHERE id = ?';
            $params = [$id];
            $types  = [Types::INTEGER];
            if ($orphanedUntil instanceof DateTimeImmutable) {
                $sql .= ' AND orphaned_since IS NOT NULL AND orphaned_since <= ? AND NOT EXISTS (SELECT 1 FROM ' . $references . ' r WHERE r.file_id = ' . $files . '.id)';
                $params[] = $orphanedUntil;
                $types[]  = Types::DATETIME_IMMUTABLE;
            }
            $row = $connection->fetchAssociative($sql . $lock, $params, $types);
            if ($row === false) {
                return null;
            }
            $connection->executeStatement('DELETE FROM ' . $references . ' WHERE file_id = ?', [$id], [Types::INTEGER]);
            $connection->executeStatement(
                'UPDATE ' . $files . ' SET orphaned_since = ? WHERE id = ?',
                [new DateTimeImmutable(FileRemover::EXPIRED_SINCE), $id],
                [Types::DATETIME_IMMUTABLE, Types::INTEGER],
            );

            return self::keys($row['storage_key'], $row['variants']);
        }, 'claim');
    }

    /**
     * Deletes the expired row `$id` (and any reference added since); true when it was deleted.
     */
    public static function delete(StorageEntityManager $entityManager, int $id): bool
    {
        $files      = $entityManager->table(StoredFile::class);
        $references = $entityManager->table(StoredFileReference::class);

        // @igor-ignore - The closure runs synchronously inside atomically(); nothing is kept.
        return $entityManager->atomically(static function (Connection $connection) use ($files, $references, $id): bool {
            $connection->executeStatement('DELETE FROM ' . $references . ' WHERE file_id = ?', [$id], [Types::INTEGER]);

            return $connection->executeStatement(
                'DELETE FROM ' . $files . ' WHERE id = ? AND orphaned_since < ?',
                [$id, new DateTimeImmutable(StoredFile::EXPIRED_BEFORE)],
                [Types::INTEGER, Types::DATETIME_IMMUTABLE],
            ) > 0;
        }, 'delete');
    }

    /**
     * The original's key and every variant key of a row's JSON.
     *
     * @return list<string>
     */
    private static function keys(mixed $storageKey, mixed $variants): array
    {
        $keys    = [(string) $storageKey];
        $decoded = is_string($variants) ? json_decode($variants, true) : $variants;
        foreach (is_array($decoded) ? $decoded : [] as $variant) {
            if (is_array($variant) && is_string($variant['key'] ?? null)) {
                $keys[] = $variant['key'];
            }
        }

        return $keys;
    }
}
