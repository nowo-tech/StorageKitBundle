<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Lifecycle;

use DateTimeImmutable;
use InvalidArgumentException;
use League\Flysystem\Config;
use Nowo\StorageKitBundle\Command\StorageSyncCommand;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Message\RunStorageSyncMessage;
use Nowo\StorageKitBundle\MessageHandler\RunStorageSyncHandler;
use Nowo\StorageKitBundle\Sync\StorageSyncer;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploader;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Bucket moves: verified copies, idempotent and resumable runs, one runner at a time (lock + claim), generic failure
 * reasons, the `--check` report and the CLI.
 */
final class SyncTest extends KernelTestCase
{
    /**
     * @param int<1, max> $width
     */
    private function upload(StorageKitTestKernel $kernel, int $width = 2000): StoredFile
    {
        return $this->service($kernel, ImageUploader::class)->upload(new InMemoryUser('alice', null), 'board', 'b1', Images::png($width, 1000), 'photo.png');
    }

    /**
     * @return array{StorageProfile, StorageProfile}
     */
    private function pair(StorageKitTestKernel $kernel): array
    {
        return [$this->profile($kernel), $this->profile($kernel, null, 'target', StorageProfile::STATE_TARGET)];
    }

    private function syncer(StorageKitTestKernel $kernel): StorageSyncer
    {
        return $this->service($kernel, StorageSyncer::class);
    }

    public function testACopyIsVerifiedIdempotentAndResumable(): void
    {
        $kernel      = $this->bootKernel();
        [$from, $to] = $this->pair($kernel);
        $this->upload($kernel);
        $this->upload($kernel, 100);
        $syncer = $this->syncer($kernel);

        // The second file's copy fails: the sync stops there with a generic reason.
        $this->buckets($kernel)->writesLeft = 3;
        $sync                               = $syncer->open($from, $to);
        self::assertTrue($syncer->run($sync));
        self::assertSame(StorageSync::FAILED, $sync->getStatus());
        self::assertSame(1, $sync->getProcessed());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}: other$/', (string) $sync->getError());

        // Resuming copies the second file only; a third run copies nothing again.
        $this->buckets($kernel)->writesLeft = null;
        $sync                               = $syncer->open($from, $to);
        self::assertTrue($syncer->run($sync));
        self::assertSame([StorageSync::COMPLETED, 2, 5, 0], [$sync->getStatus(), $sync->getProcessed(), $sync->getCopiedObjects(), $sync->getSkippedObjects()]);
        self::assertCount(5, $this->buckets($kernel)->objects('target', 'http://minio:9000'));
        self::assertSame(0, $syncer->remaining($sync));

        $again = $syncer->open($from, $to);
        self::assertSame($sync->getId(), $again->getId());
        $this->entityManager($kernel)->getConnection()->executeStatement('UPDATE storage_kit_sync SET last_file_id = 0, processed = 0');
        $this->entityManager($kernel)->refresh($again);
        self::assertTrue($syncer->run($again));
        self::assertSame(5, $again->getSkippedObjects(), 'objects already there with the same checksum are not written again');
    }

    public function testChecksumMismatchesStopTheCopy(): void
    {
        $kernel      = $this->bootKernel();
        [$from, $to] = $this->pair($kernel);
        $file        = $this->upload($kernel, 100);
        $syncer      = $this->syncer($kernel);

        $this->buckets($kernel)->corruptWrites = true;
        $sync                                  = $syncer->open($from, $to);
        $syncer->run($sync);
        self::assertSame($file->getUuid() . ': copy checksum mismatch', $sync->getError());

        $this->buckets($kernel)->corruptWrites = false;
        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE storage_kit_file SET sha256 = 'other'");
        $sync = $syncer->open($from, $to);
        $syncer->run($sync);
        self::assertSame($file->getUuid() . ': source checksum mismatch', $sync->getError());
    }

    public function testOnlyOneRunnerAtATime(): void
    {
        $kernel      = $this->bootKernel();
        [$from, $to] = $this->pair($kernel);
        $this->upload($kernel, 100);
        $syncer = $this->syncer($kernel);
        $sync   = $syncer->open($from, $to);

        $lock = $this->service($kernel, 'lock.factory');
        self::assertInstanceOf(LockFactory::class, $lock);
        $held = $lock->createLock('nowo_storage_kit/sync/' . $sync->getId());
        self::assertTrue($held->acquire());
        self::assertFalse($syncer->run($sync), 'the lock is held');
        $held->release();

        // Another runner claimed it a moment ago: not taken over.
        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE storage_kit_sync SET status = 'running', updated_at = ?", [(new DateTimeImmutable())->format('Y-m-d H:i:s')]);
        self::assertFalse($syncer->run($sync));
        // A runner that died long ago is taken over.
        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE storage_kit_sync SET updated_at = '2000-01-01 00:00:00'");
        self::assertTrue($syncer->run($sync));
        self::assertSame(StorageSync::COMPLETED, $sync->getStatus());

        $this->expectException(InvalidArgumentException::class);
        $syncer->open($from, $from);
    }

    public function testCancelAndTimeSlices(): void
    {
        $kernel      = $this->bootKernel();
        [$from, $to] = $this->pair($kernel);
        $this->upload($kernel, 100);
        $this->upload($kernel, 100);
        $syncer = $this->syncer($kernel);

        $sync = $syncer->open($from, $to);
        self::assertTrue($syncer->run($sync, 0));
        self::assertSame([StorageSync::QUEUED, 2], [$sync->getStatus(), $sync->getProcessed()], 'the slice ends after its batch');

        $handler = $this->service($kernel, RunStorageSyncHandler::class);
        $syncer->cancel($sync);
        $handler(new RunStorageSyncMessage((int) $sync->getId()));
        $handler(new RunStorageSyncMessage(999));
        self::assertSame(StorageSync::CANCELLED, $sync->getStatus());
        $syncer->cancel($sync);

        // A cancel that arrives while running stops the runner at the next batch.
        $sync->queue();
        $this->entityManager($kernel)->flush();
        $this->entityManager($kernel)->getConnection()->executeStatement('UPDATE storage_kit_sync SET last_file_id = 0, processed = 0');
        $this->entityManager($kernel)->refresh($sync);
        $connection = $this->entityManager($kernel)->getConnection();
        $syncer->run($sync, null, static function (StorageSync $running) use ($connection): void {
            $connection->executeStatement("UPDATE storage_kit_sync SET status = 'cancelled' WHERE id = ?", [$running->getId()]);
        });
        self::assertSame(StorageSync::CANCELLED, $sync->getStatus());
    }

    public function testAnUnusableProfileFailsTheSync(): void
    {
        $kernel      = $this->bootKernel();
        [$from, $to] = $this->pair($kernel);
        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE storage_kit_profile SET region = 'BAD' WHERE id = ?", [$to->getId()]);
        $this->entityManager($kernel)->refresh($to);
        $sync = $this->syncer($kernel)->open($from, $to);
        $this->syncer($kernel)->run($sync);

        self::assertSame(StorageSync::FAILED, $sync->getStatus());
        self::assertSame('profile ' . $to->getUuid() . ' is not usable', $sync->getError());
    }

    public function testTheCommandCopiesAndChecks(): void
    {
        $kernel      = $this->bootKernel();
        [$from, $to] = $this->pair($kernel);
        $file        = $this->upload($kernel);
        $app         = new Application($kernel);
        $sync        = new CommandTester($app->find(StorageSyncCommand::NAME));

        self::assertSame(2, $sync->execute(['from' => 'nope', 'to' => $to->getUuid()]));
        self::assertSame(2, $sync->execute(['from' => $from->getUuid(), 'to' => $from->getUuid()]));
        self::assertSame(1, $sync->execute(['from' => $from->getUuid(), 'to' => $to->getUuid(), '--check' => true]));
        self::assertStringContainsString('missing: 3', $sync->getDisplay());

        self::assertSame(0, $sync->execute(['from' => $from->getUuid(), 'to' => $to->getUuid()]));
        self::assertStringContainsString('1 files verified (3 objects copied, 0 already there)', $sync->getDisplay());

        $this->buckets($kernel)->adapter('http://minio:9000|target')->write('app/stray', 'x', new Config());
        self::assertSame(0, $sync->execute(['from' => $from->getUuid(), 'to' => $to->getUuid(), '--check' => true]));
        self::assertStringContainsString('unknown: 1', $sync->getDisplay());
        self::assertStringContainsString('stray', $sync->getDisplay());
        self::assertSame(0, $sync->execute(['from' => $from->getUuid(), 'to' => $from->getUuid(), '--check' => true]));

        $this->upload($kernel, 100);
        $this->buckets($kernel)->failReads = true;
        self::assertSame(1, $sync->execute(['from' => $from->getUuid(), 'to' => $to->getUuid()]));
        self::assertStringContainsString('Run the command again to resume', $sync->getDisplay());
        $this->buckets($kernel)->failReads = false;
        self::assertSame(1, $this->syncer($kernel)->remaining($this->syncer($kernel)->open($from, $to)));

        // Held elsewhere.
        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE storage_kit_sync SET status = 'running', updated_at = ?", [(new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s')]);
        self::assertSame(1, $sync->execute(['from' => $from->getUuid(), 'to' => $to->getUuid()]));
        self::assertStringContainsString('running elsewhere', $sync->getDisplay());
        self::assertNotNull($file->getId());
    }

    public function testTheCheckListsAreBounded(): void
    {
        $kernel      = $this->bootKernel();
        [$from, $to] = $this->pair($kernel);
        $adapter     = $this->buckets($kernel)->adapter('http://minio:9000|target');
        for ($i = 0; $i < StorageSyncer::CHECK_LISTED + 5; ++$i) {
            $adapter->write('app/stray-' . $i, 'x', new Config());
        }
        $report = $this->syncer($kernel)->check($from, $to);

        self::assertCount(StorageSyncer::CHECK_LISTED, $report['unknown']);
        self::assertSame(StorageSyncer::CHECK_LISTED + 5, $report['unknown_count']);
        self::assertFalse($report['truncated']);
        $tester = new CommandTester((new Application($kernel))->find(StorageSyncCommand::NAME));
        $tester->execute(['from' => $from->getUuid(), 'to' => $to->getUuid(), '--check' => true]);
        self::assertStringContainsString('(first 100)', $tester->getDisplay());
    }
}
