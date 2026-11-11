<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Functional;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Tests\Fixtures\Entity\Note;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploader;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use Nowo\StorageKitBundle\Upload\StoredFileView;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * 1.3 architecture bar: an upload never flushes the application's entity manager (rule 6), the DTO upload port with
 * its after-commit audit event (rules 1, 3 and 7).
 */
final class ArchitectureBarTest extends KernelTestCase
{
    public function testAnUploadLeavesTheHostUnitOfWorkAlone(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $manager = $this->entityManager($kernel);
        $note    = new Note('b1', 'pending');
        $manager->persist($note);

        $file = $this->service($kernel, ImageUploader::class)->upload(new InMemoryUser('alice', null), 'board', 'b1', Images::png(10, 10), 'a.png');

        self::assertTrue($manager->getUnitOfWork()->isScheduledForInsert($note), 'still pending');
        self::assertSame(0, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->noteTable($kernel)));
        self::assertNotNull($file->getId());
        self::assertSame($file, $manager->find(StoredFile::class, $file->getId()), 'the deprecated upload() returns the managed row');
        self::assertSame(1, (int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM storage_kit_file'));
        self::assertSame([StorageAuditEvent::FILE_UPLOADED], $this->audit($kernel)->actions());
    }

    public function testTheDtoPortAndTheHostControlledTransaction(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $uploader   = $this->service($kernel, ImageUploaderInterface::class);
        $connection = $this->entityManager($kernel)->getConnection();
        $alice      = new InMemoryUser('alice', null);

        $view = $uploader->uploadImage($alice, 'board', 'b1', Images::png(10, 10), 'a.png');
        self::assertInstanceOf(StoredFileView::class, $view);
        self::assertSame(['board', 'b1', 'a.png', 'alice', null], [$view->contextType, $view->contextId, $view->originalName, $view->uploadedBy, $view->scope]);
        self::assertGreaterThanOrEqual($view->size, $view->totalBytes);
        self::assertCount(1, $this->audit($kernel)->events, 'outside a transaction: at once');

        // Inside the host transaction: the row joins it, nothing is dispatched until afterCommit().
        $connection->beginTransaction();
        $held = $uploader->uploadImage($alice, 'board', 'b1', Images::png(12, 12), 'b.png');
        self::assertCount(1, $this->audit($kernel)->events);
        $connection->commit();
        $uploader->afterCommit($held);
        self::assertCount(2, $this->audit($kernel)->events);
        self::assertSame($held->uuid, $this->audit($kernel)->events[1]->details['file']);
        self::assertSame('alice', $this->audit($kernel)->events[1]->actor);

        // Rolled back: no row; the caller drops the view.
        $connection->beginTransaction();
        $dropped = $uploader->uploadImage($alice, 'board', 'b1', Images::png(14, 14), 'c.png');
        $connection->rollBack();
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM storage_kit_file WHERE uuid = ?', [$dropped->uuid]));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM storage_kit_file'));
    }

    public function testTheMetadataInsertWithAnAssignedIdentifier(): void
    {
        $kernel = $this->bootKernel();
        $bundle = $this->service($kernel, StorageEntityManager::class);
        $note   = new Note('b1', 'inserted');

        self::assertSame($note->getId(), $bundle->insert($note));

        // Inside a transaction: a savepoint; a failing insert rolls back only the savepoint and rethrows.
        $connection = $this->entityManager($kernel)->getConnection();
        $connection->beginTransaction();
        try {
            $bundle->insert($note);
            self::fail('Expected the duplicate key.');
        } catch (UniqueConstraintViolationException) {
            self::assertTrue($connection->isTransactionActive());
        }
        $connection->commit();
        self::assertSame('inserted', $this->entityManager($kernel)->getConnection()->fetchOne('SELECT body FROM ' . $this->noteTable($kernel) . ' WHERE id = ?', [$note->getId()]));
    }

    public function testAFailedInsertInsideAHostTransactionRollsBackOnlyItsSavepoint(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $uploader   = $this->service($kernel, ImageUploaderInterface::class);
        $connection = $this->entityManager($kernel)->getConnection();
        $platform   = $connection->getDatabasePlatform();
        // A database-side refusal of one row (SQLite, MySQL / MariaDB, PostgreSQL: the CI matrix).
        if ($platform instanceof SQLitePlatform) {
            $connection->executeStatement("CREATE TRIGGER storage_kit_refuse BEFORE INSERT ON storage_kit_file WHEN NEW.original_name = 'refused.png' BEGIN SELECT RAISE(ABORT, 'refused'); END");
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement("CREATE OR REPLACE FUNCTION storage_kit_refuse() RETURNS trigger AS $$ BEGIN IF NEW.original_name = 'refused.png' THEN RAISE EXCEPTION 'refused'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql");
            $connection->executeStatement('CREATE TRIGGER storage_kit_refuse BEFORE INSERT ON storage_kit_file FOR EACH ROW EXECUTE FUNCTION storage_kit_refuse()');
        } else {
            $connection->executeStatement("CREATE TRIGGER storage_kit_refuse BEFORE INSERT ON storage_kit_file FOR EACH ROW BEGIN IF NEW.original_name = 'refused.png' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused'; END IF; END");
        }

        $connection->beginTransaction();
        $connection->insert($this->noteTable($kernel), ['id' => 'n1', 'board' => 'b1', 'body' => 'host row']);
        try {
            $uploader->uploadImage(new InMemoryUser('alice', null), 'board', 'b1', Images::png(10, 10), 'refused.png');
            self::fail('Expected the refusal.');
        } catch (UploadRefused $e) {
            self::assertSame('storage', $e->reason);
        }
        self::assertTrue($connection->isTransactionActive(), 'the host transaction survives');
        $connection->commit();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $this->noteTable($kernel)));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM storage_kit_file'));
    }

    private function noteTable(StorageKitTestKernel $kernel): string
    {
        return $this->entityManager($kernel)->getClassMetadata(Note::class)->getTableName();
    }
}
