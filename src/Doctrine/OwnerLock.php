<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Throwable;

use function hash;
use function hexdec;
use function in_array;
use function substr;

/**
 * The lock that serializes the writes of one storage owner (1.7, REQ-RUNTIME-003 rule 4: "only one" active / target
 * profile and open sync per owner, which no unique key can express), used by {@see StorageEntityManager::transactional()}:
 *
 * - MySQL / MariaDB: `GET_LOCK()` taken before the transaction ({@see self::acquire()}) and released after it
 *   ({@see self::release()}), waiting at most the given seconds;
 * - PostgreSQL: `pg_advisory_xact_lock()` taken inside the transaction ({@see self::acquireInside()}), released by its
 *   commit or rollback, waiting at most the same seconds (1.7.1, audit UH-SK-3: a transaction-local `lock_timeout`
 *   set for the lock statement only, the previous value restored right after it; 0 = `pg_try_advisory_xact_lock()`,
 *   no wait);
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
     * PostgreSQL: takes the transaction-scoped advisory lock of the owner (call it inside the transaction), waiting at
     * most `$timeout` seconds (1.7.1, audit UH-SK-3). The wait is bounded by a transaction-local `lock_timeout` set for
     * the lock statement only: the previous value (the session's, or one your transaction set) is restored right after
     * it, so your own statements later in the same transaction keep their timeout. 0 tries once without waiting.
     *
     * @throws TransientStorageFailure when the lock is not obtained within `$timeout` seconds
     */
    public static function acquireInside(Connection $connection, ?string $owner, int $timeout = StorageEntityManager::OWNER_LOCK_TIMEOUT): void
    {
        if ($owner === null || !$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }
        $key = self::key($owner);
        if ($timeout <= 0) {
            // pdo_pgsql returns a bool; other drivers may return 't' / 1.
            if (!in_array($connection->fetchOne('SELECT pg_try_advisory_xact_lock(?)', [$key], [Types::BIGINT]), [true, 1, '1', 't'], true)) {
                throw new TransientStorageFailure(1, self::MESSAGE);
            }

            return;
        }
        $previous = (string) $connection->fetchOne("SELECT current_setting('lock_timeout')");
        $connection->fetchOne("SELECT set_config('lock_timeout', ?, true)", [($timeout * 1000) . 'ms']);
        try {
            $connection->executeQuery('SELECT pg_advisory_xact_lock(?)', [$key], [Types::BIGINT]);
        } catch (Throwable $e) {
            // 55P03: the transaction (or the unit's savepoint) is aborted; its rollback also undoes the set_config().
            if ((new TransientFailureClassifier())->isTransient($e)) {
                throw new TransientStorageFailure(1, self::MESSAGE, $e);
            }

            throw $e;
        }
        $connection->fetchOne("SELECT set_config('lock_timeout', ?, true)", [$previous]);
    }

    /**
     * Runs `$statement` (a row-locking read taken while the owner lock is held) with the MySQL / MariaDB row lock wait
     * bounded by `$timeout` seconds (1.7.1, audit UH2-SK-2) instead of the session's `innodb_lock_wait_timeout` (50 by
     * default): the session value is lowered for this statement only and restored right after it, also when it fails.
     * Left as it is on other platforms, for a timeout of 0, or when the session value is already lower or equal. A
     * failed restore is swallowed only when the connection is gone (the session, and its setting, went with it).
     *
     * @template T
     *
     * @param callable(): T $statement
     *
     * @return T
     */
    public static function boundedRowLockWait(Connection $connection, int $timeout, callable $statement): mixed
    {
        $session = $timeout > 0 && $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? (int) $connection->fetchOne('SELECT @@SESSION.innodb_lock_wait_timeout')
            : 0;
        if ($session <= $timeout) {
            return $statement();
        }
        $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = ' . $timeout);
        try {
            return $statement();
        } finally {
            try {
                $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = ' . $session);
            } catch (ConnectionException) {
                // The connection is gone, and the session with it.
            }
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
