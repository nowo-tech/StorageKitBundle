<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Nowo\StorageKitBundle\Doctrine\OwnerLock;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Tests\Unit\Concurrency\StorageConflictsTest;
use Nowo\TransactionContract\TransientStorageFailure;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function strlen;

/**
 * 1.7 (REQ-RUNTIME-003 rule 4): the owner lock per platform — GET_LOCK on MySQL / MariaDB, a transaction-scoped
 * advisory lock on PostgreSQL (bounded by the owner lock timeout since 1.7.1), none elsewhere; a lock not obtained in
 * time is a transient failure.
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

    public function testPostgreSqlTakesATransactionScopedAdvisoryLockWithABoundedWait(): void
    {
        $key = OwnerLock::key('owner/instance');
        self::assertGreaterThan(0, $key);
        self::assertNotSame($key, OwnerLock::key('owner/scope/acme'));

        // 1.7.1 (UH-SK-3): lock_timeout is set for the lock statement only, then your previous value comes back.
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $calls = [];
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql, array $params = []) use (&$calls): string {
            $calls[] = [$sql, $params];

            return $sql === "SELECT current_setting('lock_timeout')" ? '250ms' : 'x';
        });
        $connection->expects(self::once())->method('executeQuery')->with('SELECT pg_advisory_xact_lock(?)', [$key], [Types::BIGINT]);

        self::assertNull(OwnerLock::acquire($connection, 'owner/instance', 5));
        OwnerLock::acquireInside($connection, 'owner/instance', 3);
        OwnerLock::release($connection, null);
        self::assertSame([
            ["SELECT current_setting('lock_timeout')", []],
            ["SELECT set_config('lock_timeout', ?, true)", ['3000ms']],
            ["SELECT set_config('lock_timeout', ?, true)", ['250ms']],
        ], $calls);
    }

    public function testAPostgreSqlLockTimeoutIsTransientAndOtherErrorsStayAsTheyAre(): void
    {
        $timeout = StorageConflictsTest::storageError(['55P03', 7]);
        $other   = new RuntimeException('connection refused');
        foreach ([[$timeout, true], [$other, false]] as [$error, $transient]) {
            $connection = $this->createStub(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
            $connection->method('fetchOne')->willReturn('0');
            $connection->method('executeQuery')->willThrowException($error);
            try {
                OwnerLock::acquireInside($connection, 'owner/instance', 1);
                self::fail('expected a failure');
            } catch (TransientStorageFailure $e) {
                self::assertTrue($transient);
                self::assertSame(OwnerLock::MESSAGE, $e->getMessage());
                self::assertSame($error, $e->getPrevious());
            } catch (RuntimeException $e) {
                self::assertFalse($transient);
                self::assertSame($other, $e);
            }
        }
    }

    public function testAPostgreSqlTimeoutOfZeroTriesOnceWithoutWaiting(): void
    {
        $key = OwnerLock::key('owner/instance');
        foreach ([[true, true], ['t', true], [false, false]] as [$result, $taken]) {
            $connection = $this->createMock(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
            $connection->expects(self::once())->method('fetchOne')->with('SELECT pg_try_advisory_xact_lock(?)', [$key], [Types::BIGINT])->willReturn($result);
            $connection->expects(self::never())->method('executeQuery');
            try {
                OwnerLock::acquireInside($connection, 'owner/instance', 0);
                self::assertTrue($taken);
            } catch (TransientStorageFailure $e) {
                self::assertFalse($taken);
                self::assertSame(1, $e->retryAfterSeconds());
            }
        }
    }

    public function testTheRowLockWaitIsBoundedOnMySqlAndRestoredAfterwards(): void
    {
        // UH2-SK-2: 50 s session value, 5 s owner lock timeout: lowered for the statement only, restored after it.
        $calls      = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $connection->expects(self::once())->method('fetchOne')->with('SELECT @@SESSION.innodb_lock_wait_timeout')->willReturn('50');
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$calls): int {
            $calls[] = $sql;

            return 0;
        });
        self::assertSame('read', OwnerLock::boundedRowLockWait($connection, 5, static function () use (&$calls): string {
            $calls[] = 'read';

            return 'read';
        }));
        self::assertSame(['SET SESSION innodb_lock_wait_timeout = 5', 'read', 'SET SESSION innodb_lock_wait_timeout = 50'], $calls);

        // A failing statement restores too, and its error is the one thrown.
        $calls   = [];
        $error   = new RuntimeException('lock wait timeout');
        $failing = $this->createStub(Connection::class);
        $failing->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $failing->method('fetchOne')->willReturn('50');
        $failing->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$calls): int {
            $calls[] = $sql;

            return 0;
        });
        $thrown = null;
        try {
            OwnerLock::boundedRowLockWait($failing, 5, static function () use ($error): bool {
                throw $error;
            });
        } catch (RuntimeException $e) {
            $thrown = $e;
        }
        self::assertSame($error, $thrown);
        self::assertSame(['SET SESSION innodb_lock_wait_timeout = 5', 'SET SESSION innodb_lock_wait_timeout = 50'], $calls);
    }

    public function testTheRowLockWaitIsLeftAloneWhenAlreadyLowerOnOtherPlatformsOrForZero(): void
    {
        $lower = $this->createMock(Connection::class);
        $lower->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $lower->method('fetchOne')->willReturn('3');
        $lower->expects(self::never())->method('executeStatement');
        self::assertTrue(OwnerLock::boundedRowLockWait($lower, 5, static fn (): bool => true));

        foreach ([[new MySQLPlatform(), 0], [new PostgreSQLPlatform(), 5], [new SQLitePlatform(), 5]] as [$platform, $timeout]) {
            $connection = $this->createMock(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn($platform);
            $connection->expects(self::never())->method('fetchOne');
            $connection->expects(self::never())->method('executeStatement');
            self::assertTrue(OwnerLock::boundedRowLockWait($connection, $timeout, static fn (): bool => true));
        }
    }

    public function testAFailedRestoreIsSwallowedOnlyWhenTheConnectionIsGone(): void
    {
        $gone  = new ConnectionLost(PdoDriverException::new(new PDOException('server has gone away')), null);
        $other = new RuntimeException('syntax');
        foreach ([[$gone, true], [$other, false]] as [$failure, $swallowed]) {
            $connection = $this->createStub(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
            $connection->method('fetchOne')->willReturn(50);
            $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use ($failure): int {
                if ($sql === 'SET SESSION innodb_lock_wait_timeout = 50') {
                    throw $failure;
                }

                return 0;
            });
            try {
                self::assertTrue(OwnerLock::boundedRowLockWait($connection, 1, static fn (): bool => true));
                self::assertTrue($swallowed);
            } catch (RuntimeException $e) {
                self::assertFalse($swallowed);
                self::assertSame($other, $e);
            }
        }
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
