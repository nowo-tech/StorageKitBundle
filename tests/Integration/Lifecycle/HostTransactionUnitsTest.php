<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Lifecycle;

use Doctrine\DBAL\Connection;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Tests\Fixtures\Entity\Note;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use RuntimeException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Throwable;

/**
 * Audit NH-1 / NH-2 (1.5): the bundle's DBAL writes run as one unit — a savepoint inside the host's transaction, a
 * transaction of their own otherwise. A failing bundle statement inside the host's transaction rolls back only the
 * bundle's change: the host catches the error and still commits its own work (PostgreSQL aborts the whole transaction
 * on a failed statement without a savepoint). Runs on every CI database.
 */
final class HostTransactionUnitsTest extends KernelTestCase
{
    public function testAFailingBundleUpdateLeavesTheHostTransactionUsable(): void
    {
        [$writes, $connection, $id, $notes] = $this->setUpFile();

        $connection->beginTransaction();
        $connection->insert($notes, ['id' => 'host-1', 'board' => 'b1', 'body' => 'before']);
        try {
            // NOT NULL column: the statement fails on every platform.
            $writes->updateRows(StoredFile::class, [$id], ['contextType' => null]);
            self::fail('the update must fail');
        } catch (Throwable) {
        }
        self::assertTrue($connection->isTransactionActive());
        $connection->insert($notes, ['id' => 'host-2', 'board' => 'b1', 'body' => 'after']);
        $connection->commit();

        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $notes));
        self::assertSame('board', $connection->fetchOne('SELECT context_type FROM storage_kit_file WHERE id = ?', [$id]));
    }

    public function testAUnitIsAllOrNothingInsideAndOutsideTheHostTransaction(): void
    {
        [$writes, $connection, $id, $notes] = $this->setUpFile();
        $failing                            = static function (Connection $connection) use ($writes, $id): never {
            $writes->updateRows(StoredFile::class, [$id], ['originalName' => 'changed']);

            throw new RuntimeException('second statement failed');
        };

        // Outside a transaction: a transaction of its own, rolled back.
        $thrown = null;
        try {
            $writes->atomically($failing, 'erase');
        } catch (RuntimeException $e) {
            $thrown = $e->getMessage();
        }
        self::assertSame('second statement failed', $thrown, 'rethrown');
        self::assertFalse($connection->isTransactionActive());
        self::assertSame('a.png', $connection->fetchOne('SELECT original_name FROM storage_kit_file WHERE id = ?', [$id]));

        // Inside the host's: its savepoint is rolled back, the host commits its own work.
        $connection->beginTransaction();
        $connection->insert($notes, ['id' => 'host-1', 'board' => 'b1', 'body' => 'kept']);
        $thrown = null;
        try {
            $writes->atomically($failing, 'erase');
        } catch (RuntimeException $e) {
            $thrown = $e->getMessage();
        }
        self::assertSame('second statement failed', $thrown, 'rethrown');
        self::assertSame(3, $writes->atomically(static fn (Connection $connection): int => 3));
        $connection->commit();
        self::assertSame('a.png', $connection->fetchOne('SELECT original_name FROM storage_kit_file WHERE id = ?', [$id]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $notes));

        // A successful unit commits outside a transaction.
        self::assertSame(1, $writes->atomically(static fn (Connection $connection): int => $writes->updateRows(StoredFile::class, [$id], ['originalName' => 'b.png'])));
        self::assertSame('b.png', $connection->fetchOne('SELECT original_name FROM storage_kit_file WHERE id = ?', [$id]));
    }

    public function testTheOriginalErrorSurvivesASavepointThatIsGone(): void
    {
        [$writes, $connection] = $this->setUpFile();
        $connection->beginTransaction();
        $thrown = null;
        try {
            // The unit's work ends the host's transaction itself (as a database does on some errors): the savepoint
            // rollback fails too, and the original error is the one rethrown.
            $writes->atomically(static function (Connection $connection): never {
                $connection->rollBack();

                throw new RuntimeException('original error');
            });
        } catch (RuntimeException $e) {
            $thrown = $e->getMessage();
        }
        self::assertSame('original error', $thrown);
        self::assertFalse($connection->isTransactionActive());
    }

    /**
     * @return array{StorageEntityManager, Connection, int, string}
     */
    private function setUpFile(): array
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $view       = $this->service($kernel, ImageUploaderInterface::class)->uploadImage(new InMemoryUser('alice', null), 'board', 'b1', Images::png(10, 10), 'a.png');
        $writes     = $this->service($kernel, StorageEntityManager::class);
        $connection = $writes->connection();
        $id         = (int) $connection->fetchOne('SELECT id FROM storage_kit_file WHERE uuid = ?', [$view->uuid]);

        return [$writes, $connection, $id, $writes->manager()->getClassMetadata(Note::class)->getTableName()];
    }
}
