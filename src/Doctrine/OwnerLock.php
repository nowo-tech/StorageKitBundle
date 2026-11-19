<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Nowo\TransactionContract\TransientStorageFailure;

use function hash;
use function hexdec;
use function substr;

/**
 * The lock that serializes the writes of one storage owner (1.7, REQ-RUNTIME-003 rule 4: "only one" active / target
 * profile and open sync per owner, which no unique key can express), used by {@see StorageEntityManager::transactional()}:
 *
 * - MySQL / MariaDB: `GET_LOCK()` taken before the transaction ({@see self::acquire()}) and released after it
 *   ({@see self::release()}), waiting at most the given seconds;
 * - PostgreSQL: `pg_advisory_xact_lock()` taken inside the transaction ({@see self::acquireInside()}), released by its
 *   commit or rollback, waiting at most the session's `lock_timeout` (55P03 otherwise);
 * - other platforms (SQLite: one writer at a time): none.
 *
 * A lock not obtained in time throws {@see TransientStorageFailure} (the bundle routes answer CONFLICT_001).
 * Stateless (static methods only).
 *
 * @internal
 */
final class OwnerLock
{
    /** Client-safe message of the transient failure thrown when the lock is not obtained in time. */
    public const MESSAGE = 'Another change of the same storage owner is in progress. Retry later.';

    /**
     * MySQL / MariaDB: takes the named lock and returns its name (to release), or null on another platform or without
     * an owner key.
     *
     * @throws TransientStorageFailure when the lock is not obtained within `$timeout` seconds
     */
    public static function acquire(Connection $connection, ?string $owner, int $timeout): ?string
    {
        if ($owner === null || !$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return null;
        }
        $name = self::name($owner);
        if ((int) $connection->fetchOne('SELECT GET_LOCK(?, ?)', [$name, $timeout]) !== 1) {
            throw new TransientStorageFailure(1, self::MESSAGE);
        }

        return $name;
    }

    /**
     * PostgreSQL: takes the transaction-scoped advisory lock of the owner (call it inside the transaction).
     */
    public static function acquireInside(Connection $connection, ?string $owner): void
    {
        if ($owner !== null && $connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $connection->executeQuery('SELECT pg_advisory_xact_lock(?)', [self::key($owner)], [Types::BIGINT]);
        }
    }

    /**
     * Releases a lock {@see self::acquire()} took (nothing for null).
     */
    public static function release(Connection $connection, ?string $name): void
    {
        if ($name !== null) {
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    /**
     * The MySQL / MariaDB lock name of an owner key (at most 64 characters).
     */
    public static function name(string $owner): string
    {
        return 'nowo_storage_kit:' . substr(hash('sha256', $owner), 0, 40);
    }

    /**
     * The PostgreSQL advisory lock key of an owner key (a positive 60-bit integer).
     */
    public static function key(string $owner): int
    {
        return (int) hexdec(substr(hash('sha256', 'nowo_storage_kit:' . $owner), 0, 15));
    }
}
