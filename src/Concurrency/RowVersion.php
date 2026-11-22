<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Concurrency;

use Doctrine\DBAL\Connection;
use Nowo\StorageKitBundle\Entity\StorageProfile;

use function is_numeric;
use function sprintf;

/**
 * Optimistic row version of a bucket form (2.1, REQ-RUNTIME-003 rule 4): the `row_version` column of
 * {@see StorageProfile}, compared and advanced with one atomic statement.
 *
 * - {@see self::read()} gives the version a page is built from, read from the database (never the identity map: a
 *   manager that kept a loaded row across requests in worker mode cannot make a stale page look current).
 * - {@see self::advance()} runs `UPDATE … SET row_version = row_version + 1 WHERE id = ? [AND row_version = ?]`.
 *   Inside the save's transaction it also takes the row lock until the commit, so a second save that lost the race
 *   waits for the winner and then matches no row (MySQL / MariaDB and PostgreSQL READ COMMITTED re-read the row after
 *   the wait).
 *
 * Stateless: static methods only, nothing kept between requests (FrankenPHP worker mode).
 */
final class RowVersion
{
    /** The version a missing or unreadable token stands for: never stored (versions start at 1). */
    public const MISSING = 0;

    /**
     * The current version of the row `$id` of `$table`, or {@see self::MISSING} when the row does not exist.
     */
    public static function read(Connection $connection, string $table, int $id): int
    {
        $version = $connection->fetchOne(sprintf('SELECT row_version FROM %s WHERE id = ?', $table), [$id]);

        return is_numeric($version) ? (int) $version : self::MISSING;
    }

    /**
     * Advances the row `$id` by one version. With `$expected`, only while the row still has that version (the page's
     * token); null advances it unchecked (a write with no page behind it), so pages opened before are refused as stale.
     *
     * @return bool whether the row was advanced (false: changed since the page was built, or gone)
     */
    public static function advance(Connection $connection, string $table, int $id, ?int $expected): bool
    {
        $sql    = sprintf('UPDATE %s SET row_version = row_version + 1 WHERE id = ?', $table);
        $params = [$id];
        if ($expected !== null) {
            $sql .= ' AND row_version = ?';
            $params[] = $expected;
        }

        return (int) $connection->executeStatement($sql, $params) === 1;
    }
}
