<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 2.2 (REQ-RUNTIME-003 rule 3, the lost-savepoint pattern): a unit rolls back to the level it started at; when that
 * fails (the savepoint or transaction is gone), the connection is closed so it reconnects clean.
 */
final class LostSavepointRepairTest extends TestCase
{
    public function testARollbackToTheEntryLevelLeavesTheConnectionOpen(): void
    {
        $level      = 3;
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int {
            return $level;
        });
        $connection->expects(self::exactly(2))->method('rollBack')->willReturnCallback(static function () use (&$level): void {
            --$level;
        });
        $connection->expects(self::never())->method('close');
        StorageEntityManager::rollBackTo($connection, 1);
        self::assertSame(1, $level);
    }

    public function testAFailedRollbackClosesTheConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(1);
        $connection->method('rollBack')->willThrowException(new RuntimeException('SAVEPOINT does not exist'));
        $connection->expects(self::once())->method('close');
        StorageEntityManager::rollBackTo($connection, 0);
    }

    public function testALostSavepointClosesTheConnection(): void
    {
        $gone = $this->createMock(Connection::class);
        $gone->method('rollbackSavepoint')->willThrowException($this->createStub(DriverException::class));
        $gone->expects(self::once())->method('close');
        StorageEntityManager::rollBackSavepoint($gone, 'nowo_storage_kit_unit_x');

        $kept = $this->createMock(Connection::class);
        $kept->expects(self::once())->method('rollbackSavepoint')->with('nowo_storage_kit_unit_x');
        $kept->expects(self::never())->method('close');
        StorageEntityManager::rollBackSavepoint($kept, 'nowo_storage_kit_unit_x');
    }
}
