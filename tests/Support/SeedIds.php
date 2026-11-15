<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * Test seeding with raw DBAL `INSERT`s into bundle tables (as a row written before the bundle, or by a host): where
 * the ORM maps the id with a sequence (PostgreSQL with DBAL 3: the column has no default) the id is taken from that
 * sequence, as the product code does.
 */
final class SeedIds
{
    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $types
     */
    public static function insert(Connection $connection, string $table, array $row, array $types = []): void
    {
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform && !isset($row['id'])
            && $connection->fetchOne("SELECT column_default FROM information_schema.columns WHERE table_name = ? AND column_name = 'id'", [$table]) === null) {
            $row = ['id' => (int) $connection->fetchOne('SELECT nextval(?)', [$table . '_id_seq'])] + $row;
        }
        $connection->insert($table, $row, $types);
    }
}
