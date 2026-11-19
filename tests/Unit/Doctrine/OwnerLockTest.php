<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Nowo\StorageKitBundle\Doctrine\OwnerLock;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;

use function strlen;

/**
 * 1.7 (REQ-RUNTIME-003 rule 4): the owner lock per platform — GET_LOCK on MySQL / MariaDB, a transaction-scoped
 * advisory lock on PostgreSQL, none elsewhere; a lock not obtained in time is a transient failure.
 */
final class OwnerLockTest extends TestCase
{
    public function testMySqlAndMariaDbTakeAndReleaseANamedLock(): void
    {
        $name = OwnerLock::name(StorageProfileRepository::ownerLock('acme'));
        self::assertLessThanOrEqual(64, strlen($name));
        self::assertNotSame($name, OwnerLock::name(StorageProfileRepository::ownerLock(null)));

        foreach ([new MySQLPlatform(), new MariaDBPlatform()] as $platform) {
            $connection = $this->createMock(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn($platform);
            $connection->expects(self::exactly(2))->method('fetchOne')->willReturnCallback(static function (string $sql, array $params) use ($name): int {
                self::assertSame($name, $params[0]);
                if ($sql === 'SELECT GET_LOCK(?, ?)') {
                    self::assertSame(3, $params[1]);

                    return 1;
                }
                self::assertSame('SELECT RELEASE_LOCK(?)', $sql);

                return 1;
            });
            $connection->expects(self::never())->method('executeQuery');

            $taken = OwnerLock::acquire($connection, 'owner/scope/acme', 3);
            self::assertSame($name, $taken);
            OwnerLock::acquireInside($connection, 'owner/scope/acme');
            OwnerLock::release($connection, $taken);
        }
    }

    public function testALockNotObtainedInTimeIsTransient(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('fetchOne')->willReturn(0);

        try {
            OwnerLock::acquire($connection, 'owner/instance', 1);
            self::fail('expected a transient failure');
        } catch (TransientStorageFailure $e) {
            self::assertSame(1, $e->retryAfterSeconds());
            self::assertSame(OwnerLock::MESSAGE, $e->getMessage());
        }
    }

    public function testPostgreSqlTakesATransactionScopedAdvisoryLock(): void
    {
        $key = OwnerLock::key('owner/instance');
        self::assertGreaterThan(0, $key);
        self::assertNotSame($key, OwnerLock::key('owner/scope/acme'));

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::once())->method('executeQuery')->with('SELECT pg_advisory_xact_lock(?)', [$key], [Types::BIGINT]);

        self::assertNull(OwnerLock::acquire($connection, 'owner/instance', 5));
        OwnerLock::acquireInside($connection, 'owner/instance');
        OwnerLock::release($connection, null);
    }

    public function testOtherPlatformsAndNoOwnerTakeNothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::never())->method('executeQuery');

        self::assertNull(OwnerLock::acquire($connection, 'owner/instance', 5));
        OwnerLock::acquireInside($connection, 'owner/instance');

        $postgres = $this->createMock(Connection::class);
        $postgres->expects(self::never())->method('getDatabasePlatform');
        self::assertNull(OwnerLock::acquire($postgres, null, 5));
        OwnerLock::acquireInside($postgres, null);
    }
}
