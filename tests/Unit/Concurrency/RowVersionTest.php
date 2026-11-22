<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Nowo\StorageKitBundle\Concurrency\RowVersion;
use Nowo\StorageKitBundle\Concurrency\StaleEditException;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use PHPUnit\Framework\TestCase;

/**
 * 2.1: the compare-and-set of a bucket's row version (one atomic statement), on SQLite.
 */
final class RowVersionTest extends TestCase
{
    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE profiles (id INTEGER PRIMARY KEY, row_version INTEGER NOT NULL DEFAULT 1)');
        $connection->executeStatement('INSERT INTO profiles (id) VALUES (7)');

        return $connection;
    }

    public function testAdvanceComparesAndSets(): void
    {
        $connection = $this->connection();
        self::assertSame(1, RowVersion::read($connection, 'profiles', 7));

        self::assertFalse(RowVersion::advance($connection, 'profiles', 7, 2), 'a newer version than stored');
        self::assertFalse(RowVersion::advance($connection, 'profiles', 7, RowVersion::MISSING));
        self::assertTrue(RowVersion::advance($connection, 'profiles', 7, 1));
        self::assertFalse(RowVersion::advance($connection, 'profiles', 7, 1), 'the same page twice');
        self::assertSame(2, RowVersion::read($connection, 'profiles', 7));

        // Unchecked (no page behind the write): advanced all the same.
        self::assertTrue(RowVersion::advance($connection, 'profiles', 7, null));
        self::assertSame(3, RowVersion::read($connection, 'profiles', 7));
    }

    public function testAMissingRow(): void
    {
        $connection = $this->connection();
        self::assertSame(RowVersion::MISSING, RowVersion::read($connection, 'profiles', 8));
        self::assertFalse(RowVersion::advance($connection, 'profiles', 8, 1));
        self::assertFalse(RowVersion::advance($connection, 'profiles', 8, null));
    }

    public function testTheStaleAnswerAndANewProfile(): void
    {
        $stale = new StaleEditException(7, 3);
        self::assertSame(7, $stale->id);
        self::assertSame(3, $stale->expected);
        self::assertStringNotContainsString('7', $stale->getMessage(), 'no identifier in the message');
        self::assertSame(1, (new StorageProfile())->getRowVersion());
    }
}
