<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Lifecycle;

use DateTimeImmutable;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Command\PurgeFilesCommand;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Entity\StoredFileReference;
use Nowo\StorageKitBundle\Lifecycle\FilePurger;
use Nowo\StorageKitBundle\PersonalData\StoredFilesPersonalData;
use Nowo\StorageKitBundle\Tests\Fixtures\Boards;
use Nowo\StorageKitBundle\Tests\Fixtures\Entity\Note;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploader;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * REQ-MEDIA-005 reference-aware purge: texts point at files (URI or download path), only files of the text's own
 * context are pinned, dropped files become orphans, orphans older than the retention are deleted from their bucket,
 * texts / contexts deleted by SQL are caught by the purge; GDPR export and erasure.
 */
final class ReferencesAndPurgeTest extends KernelTestCase
{
    private function upload(StorageKitTestKernel $kernel, string $board = 'b1', string $user = 'alice'): StoredFile
    {
        return $this->service($kernel, ImageUploader::class)->upload(new InMemoryUser($user, null), 'board', $board, Images::png(2000, 1000), 'photo.png');
    }

    private function note(StorageKitTestKernel $kernel, string $body, string $board = 'b1'): Note
    {
        $note    = new Note($board, $body);
        $manager = $this->entityManager($kernel);
        $manager->persist($note);
        $manager->flush();

        return $note;
    }

    private function orphanedSince(StorageKitTestKernel $kernel, StoredFile $file): mixed
    {
        return $this->entityManager($kernel)->getConnection()->fetchOne('SELECT orphaned_since FROM storage_kit_file WHERE id = ?', [$file->getId()]);
    }

    private function references(StorageKitTestKernel $kernel): int
    {
        return (int) $this->entityManager($kernel)->getConnection()->fetchOne('SELECT COUNT(*) FROM storage_kit_file_reference');
    }

    public function testSavingAndEditingTextsTracksTheReferences(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $a     = $this->upload($kernel);
        $b     = $this->upload($kernel);
        $other = $this->upload($kernel, 'pub');
        self::assertNotNull($this->orphanedSince($kernel, $a));

        // URI and download-path forms both count; another context's file is not pinned.
        $note = $this->note($kernel, '![a](storage-file:' . $a->getUuid() . ') ![b](/storage/files/' . $b->getUuid() . '/preview) ![x](storage-file:' . $other->getUuid() . ')');
        self::assertSame(2, $this->references($kernel));
        self::assertNull($this->orphanedSince($kernel, $a));
        self::assertNull($this->orphanedSince($kernel, $b));
        self::assertNotNull($this->orphanedSince($kernel, $other));

        // A second text keeps a file alive when the first one drops it.
        $this->note($kernel, '![a](storage-file:' . $a->getUuid() . ')');
        $note->setBody('only ![b](storage-file:' . $b->getUuid() . ')');
        $this->entityManager($kernel)->flush();
        self::assertSame(2, $this->references($kernel));
        self::assertNull($this->orphanedSince($kernel, $a));

        // An edit without files on either side costs nothing; removing the text orphans its files.
        $plain = $this->note($kernel, 'plain');
        $plain->setBody('still plain');
        $this->entityManager($kernel)->flush();
        $this->entityManager($kernel)->remove($note);
        $this->entityManager($kernel)->flush();
        self::assertNotNull($this->orphanedSince($kernel, $b));
        self::assertSame(1, $this->references($kernel));

        // A text without a context references nothing.
        $this->note($kernel, '![a](storage-file:' . $b->getUuid() . ')', 'none');
        self::assertSame(1, $this->references($kernel));
    }

    public function testThePurgeDeletesOldOrphansOnly(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $kept   = $this->upload($kernel);
        $orphan = $this->upload($kernel);
        $this->note($kernel, '![a](storage-file:' . $kept->getUuid() . ')');
        $purger   = $this->service($kernel, FilePurger::class);
        $orphanId = $orphan->getId();

        self::assertSame(['references' => 0, 'contexts' => 0, 'orphaned' => 0, 'purged' => 0, 'failed' => 0], $purger->run(new DateTimeImmutable()));
        $stats = $purger->run(new DateTimeImmutable('+8 days'));
        self::assertSame(1, $stats['purged']);
        self::assertCount(3, $this->buckets($kernel)->objects('files', 'http://minio:9000'));
        self::assertNull($this->entityManager($kernel)->find(StoredFile::class, $orphanId));
        self::assertNotNull($this->entityManager($kernel)->find(StoredFile::class, $kept->getId()));
        self::assertContains(StorageAuditEvent::FILES_PURGED, $this->audit($kernel)->actions());
    }

    public function testTextsAndContextsDeletedBySqlAreCaught(): void
    {
        $kernel = $this->bootKernel(['purge' => ['batch_size' => 10]]);
        $this->profile($kernel);
        $a = $this->upload($kernel);
        $b = $this->upload($kernel, 'b2', 'bob');
        $this->note($kernel, '![a](storage-file:' . $a->getUuid() . ')');
        $this->note($kernel, '![b](storage-file:' . $b->getUuid() . ')', 'b2');
        $connection = $this->entityManager($kernel)->getConnection();

        // The text of `a` is deleted by SQL; the board of `b` disappears.
        $connection->executeStatement("DELETE FROM test_note WHERE board = 'b1'");
        unset($this->service($kernel, Boards::class)->boards['b2']);
        $stats = $this->service($kernel, FilePurger::class)->run(new DateTimeImmutable());

        self::assertSame(1, $stats['references']);
        self::assertSame(1, $stats['contexts']);
        self::assertSame(0, $this->references($kernel));
        self::assertNotNull($this->orphanedSince($kernel, $a));
        self::assertNotNull($this->orphanedSince($kernel, $b));
    }

    public function testABucketThatRefusesKeepsTheRowForTheNextRun(): void
    {
        $kernel                              = $this->bootKernel();
        $profile                             = $this->profile($kernel);
        $file                                = $this->upload($kernel);
        $this->buckets($kernel)->failDeletes = true;
        $tester                              = new CommandTester((new Application($kernel))->find(PurgeFilesCommand::NAME));

        self::assertSame(1, $tester->execute(['--days' => '0']));
        self::assertStringContainsString('failed: 1', $tester->getDisplay());
        self::assertNotNull($this->entityManager($kernel)->find(StoredFile::class, $file->getId()));

        // A profile that is not usable any more keeps the row too.
        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE storage_kit_profile SET region = 'BAD'");
        $this->entityManager($kernel)->clear();
        self::assertSame(1, $tester->execute(['--days' => '0']));

        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE storage_kit_profile SET region = 'us-east-1'");
        $this->entityManager($kernel)->clear();
        $this->buckets($kernel)->failDeletes = false;
        self::assertSame(0, $tester->execute(['--days' => '0']));
        self::assertStringContainsString('purged: 1', $tester->getDisplay());
        self::assertSame(2, $tester->execute(['--days' => 'abc']));
        self::assertSame(0, $tester->execute([]));
        self::assertNotNull($profile->getId());
    }

    public function testPersonalDataExportAndErasure(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $shown  = $this->upload($kernel);
        $hidden = $this->upload($kernel);
        $this->upload($kernel, 'b2', 'bob');
        $this->note($kernel, '![a](storage-file:' . $shown->getUuid() . ')');
        $data = $this->service($kernel, StoredFilesPersonalData::class);

        $export = $data->export('alice');
        self::assertCount(2, $export);
        self::assertSame([$shown->getUuid(), true], [$export[0]['uuid'], $export[0]['in_use']]);
        self::assertFalse($export[1]['in_use']);
        self::assertStringStartsWith('http://localhost/storage/files/' . $shown->getUuid() . '/original', (string) $export[0]['url']);
        self::assertSame('board/b1', $export[0]['context']);

        self::assertSame(2, $data->erase('alice'));
        self::assertSame([], $data->export('alice'));
        $this->entityManager($kernel)->clear();
        $row = $this->entityManager($kernel)->find(StoredFile::class, $shown->getId());
        self::assertSame([StoredFile::ANONYMOUS_NAME, null], [$row?->getOriginalName(), $row?->getUploadedBy()]);
        self::assertNull($row?->getOrphanedSince());
        self::assertSame('2000', $this->entityManager($kernel)->find(StoredFile::class, $hidden->getId())?->getOrphanedSince()?->format('Y'));
        self::assertCount(1, $data->export('bob'));
        self::assertContains(StorageAuditEvent::FILES_ERASED, $this->audit($kernel)->actions());
        self::assertSame(1, $this->entityManager($kernel)->getRepository(StoredFileReference::class)->count([]));
    }

    public function testATablePrefixIsAppliedToTheRawStatements(): void
    {
        $kernel = $this->bootKernel(['doctrine' => ['table_prefix' => 'app_']]);
        $this->profile($kernel);
        $file = $this->upload($kernel);
        $this->note($kernel, '![a](storage-file:' . $file->getUuid() . ')');

        $tables = $this->entityManager($kernel)->getConnection()->createSchemaManager()->listTableNames();
        self::assertContains('app_storage_kit_file', $tables);
        self::assertSame(1, (int) $this->entityManager($kernel)->getConnection()->fetchOne('SELECT COUNT(*) FROM app_storage_kit_file_reference'));
        self::assertSame(0, $this->service($kernel, FilePurger::class)->run(new DateTimeImmutable('+30 days'))['purged']);
    }
}
