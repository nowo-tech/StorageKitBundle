<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Lifecycle;

use DateTimeImmutable;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Lifecycle\FilePurger;
use Nowo\StorageKitBundle\Lifecycle\FileRemover;
use Nowo\StorageKitBundle\Lifecycle\FileRemoverInterface;
use Nowo\StorageKitBundle\Reference\FileReferencesInterface;
use Nowo\StorageKitBundle\Tests\Fixtures\AvatarContext;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploader;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use Nowo\StorageKitBundle\Upload\SquareImageProcessor;
use Nowo\StorageKitBundle\Upload\SquareImageUploaderInterface;
use Nowo\StorageKitBundle\Upload\StoredFileView;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function count;
use function strtoupper;

/**
 * 1.6: square image uploads (profile pictures, logos) through the usual context / bucket / limit flow, served by the
 * download route under the usual variant names, and the removal port (now, or left to the purge).
 */
final class SquareImagesAndRemovalTest extends KernelTestCase
{
    private function square(StorageKitTestKernel $kernel, string $board = 'b1', string $user = 'alice'): StoredFileView
    {
        return $this->service($kernel, SquareImageUploaderInterface::class)->uploadSquareImage(new InMemoryUser($user, null), 'board', $board, Images::png(300, 200), 'me.png');
    }

    private function rows(StorageKitTestKernel $kernel): int
    {
        return (int) $this->entityManager($kernel)->getConnection()->fetchOne('SELECT COUNT(*) FROM storage_kit_file');
    }

    public function testASquareImageIsStoredInThreeWebpSquaresAndServed(): void
    {
        $kernel = $this->bootKernel(['upload' => ['square' => ['size' => 128, 'max_bytes' => 4096]]]);
        $this->profile($kernel);
        self::assertSame($this->service($kernel, ImageUploader::class), $this->service($kernel, SquareImageUploaderInterface::class));
        self::assertSame(['original' => 128, 'preview' => 64, 'thumb' => 32], $this->service($kernel, SquareImageProcessor::class)->sides());

        $view = $this->square($kernel);
        self::assertSame(['image/webp', 128, 128], [$view->mimeType, $view->width, $view->height]);
        self::assertSame(['preview', 'thumb'], array_keys($view->variants));
        self::assertSame(32, $view->variants['thumb']['width']);
        self::assertCount(3, $this->buckets($kernel)->objects('files', 'http://minio:9000'));
        self::assertContains(StorageAuditEvent::FILE_UPLOADED, $this->audit($kernel)->actions());

        $client = $this->client($kernel, 'alice');
        $client->request('GET', '/storage/files/' . $view->uuid . '/thumb');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('image/webp', $client->getResponse()->headers->get('Content-Type'));

        // The square checks and the context apply.
        foreach ([
            [static fn (SquareImageUploaderInterface $u): StoredFileView => $u->uploadSquareImage(new InMemoryUser('alice', null), 'board', 'b1', Images::gif(10, 10), 'a.gif'), UploadRefused::TYPE],
            [static fn (SquareImageUploaderInterface $u): StoredFileView => $u->uploadSquareImage(new InMemoryUser('alice', null), 'board', 'b1', Images::png(2000, 2000), 'big.png'), UploadRefused::SIZE],
            [static fn (SquareImageUploaderInterface $u): StoredFileView => $u->uploadSquareImage(new InMemoryUser('alice', null), 'board', 'b2', Images::png(10, 10), 'a.png'), UploadRefused::CONTEXT],
        ] as [$upload, $reason]) {
            try {
                $upload($this->service($kernel, SquareImageUploaderInterface::class));
                self::fail('accepted');
            } catch (UploadRefused $e) {
                self::assertSame($reason, $e->reason);
            }
        }
        self::assertSame(1, $this->rows($kernel));
    }

    public function testASquareOnlyContextRefusesOrdinaryUploadsAndTheEndpoint(): void
    {
        $kernel = $this->bootKernel(extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(AvatarContext::class)->tag('nowo_storage_kit.file_context');
        });
        $this->profile($kernel);
        $alice = new InMemoryUser('alice', null);

        $view = $this->service($kernel, SquareImageUploaderInterface::class)->uploadSquareImage($alice, 'avatar', 'alice', Images::png(40, 40), 'me.png');
        self::assertSame('image/webp', $view->mimeType);
        foreach ([[$alice, 'alice'], [new InMemoryUser('bob', null), 'alice']] as [$user, $id]) {
            try {
                $this->service($kernel, ImageUploaderInterface::class)->uploadImage($user, 'avatar', $id, Images::gif(10, 10), 'a.gif');
                self::fail('accepted');
            } catch (UploadRefused $e) {
                self::assertSame(UploadRefused::CONTEXT, $e->reason);
            }
        }
        try {
            $this->service($kernel, SquareImageUploaderInterface::class)->uploadSquareImage(new InMemoryUser('bob', null), 'avatar', 'alice', Images::png(40, 40), 'me.png');
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::CONTEXT, $e->reason);
        }
        self::assertSame(1, $this->rows($kernel));

        // The download route serves the square to anyone the context lets read it.
        $client = $this->client($kernel, null);
        $client->request('GET', '/storage/files/' . $view->uuid . '/preview');
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testInsideAHostTransactionTheAuditWaitsForTheCommit(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $connection = $this->entityManager($kernel)->getConnection();
        $connection->beginTransaction();
        $view = $this->square($kernel);
        self::assertNotContains(StorageAuditEvent::FILE_UPLOADED, $this->audit($kernel)->actions());
        $connection->commit();
        $this->service($kernel, ImageUploader::class)->afterCommit($view);
        self::assertContains(StorageAuditEvent::FILE_UPLOADED, $this->audit($kernel)->actions());
    }

    public function testRemovingAFileDeletesItsObjectsReferencesAndRow(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $view    = $this->square($kernel);
        $kept    = $this->square($kernel);
        $remover = $this->service($kernel, FileRemoverInterface::class);
        self::assertInstanceOf(FileRemover::class, $remover);
        $this->service($kernel, FileReferencesInterface::class)->sync('avatar', 'u1', '![](storage-file:' . $view->uuid . ')', ['board', 'b1']);
        self::assertSame(1, (int) $this->entityManager($kernel)->getConnection()->fetchOne('SELECT COUNT(*) FROM storage_kit_file_reference'));

        // Another context, a look-alike context id, a malformed id or context: nothing happens.
        self::assertFalse($remover->remove($view->uuid, 'board', 'b2'));
        self::assertFalse($remover->remove($view->uuid, 'board', 'B1'));
        self::assertFalse($remover->remove($view->uuid, 'Board', 'b1'));
        self::assertFalse($remover->remove('not-a-uuid', 'board', 'b1'));
        self::assertFalse($remover->remove($view->uuid, 'board', '../b1'));
        self::assertFalse($remover->remove('00000000-0000-4000-8000-000000000000', 'board', 'b1'));
        self::assertSame(2, $this->rows($kernel));

        self::assertTrue($remover->remove(strtoupper($view->uuid), 'board', 'b1'));
        self::assertSame(1, $this->rows($kernel));
        self::assertCount(3, $this->buckets($kernel)->objects('files', 'http://minio:9000'));
        self::assertSame(0, (int) $this->entityManager($kernel)->getConnection()->fetchOne('SELECT COUNT(*) FROM storage_kit_file_reference'));
        self::assertContains(StorageAuditEvent::FILE_REMOVED, $this->audit($kernel)->actions());
        self::assertFalse($remover->remove($view->uuid, 'board', 'b1'), 'already gone');

        // Inside a host transaction: removed, the audit is not recorded (rule 7).
        $before     = count($this->audit($kernel)->events);
        $connection = $this->entityManager($kernel)->getConnection();
        $connection->beginTransaction();
        self::assertTrue($remover->remove($kept->uuid, 'board', 'b1'));
        $connection->commit();
        self::assertCount($before, $this->audit($kernel)->events);
        self::assertSame(0, $this->rows($kernel));
    }

    public function testARefusingBucketLeavesTheFileToThePurge(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $view = $this->square($kernel);
        $this->service($kernel, FileReferencesInterface::class)->sync('avatar', 'u1', '![](storage-file:' . $view->uuid . ')', ['board', 'b1']);
        $this->buckets($kernel)->failDeletes = true;

        self::assertTrue($this->service($kernel, FileRemoverInterface::class)->remove($view->uuid, 'board', 'b1'));
        self::assertSame(1, $this->rows($kernel));
        self::assertSame(0, (int) $this->entityManager($kernel)->getConnection()->fetchOne('SELECT COUNT(*) FROM storage_kit_file_reference'));
        self::assertNotNull($this->entityManager($kernel)->getConnection()->fetchOne('SELECT orphaned_since FROM storage_kit_file'));

        $this->buckets($kernel)->failDeletes = false;
        self::assertSame(1, $this->service($kernel, FilePurger::class)->run(new DateTimeImmutable())['purged']);
        self::assertSame(0, $this->rows($kernel));
        self::assertSame([], $this->buckets($kernel)->objects('files', 'http://minio:9000'));
    }

    public function testAnUnusableProfileLeavesTheFileToThePurge(): void
    {
        $kernel  = $this->bootKernel();
        $profile = $this->profile($kernel);
        $view    = $this->square($kernel);
        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE storage_kit_profile SET bucket = '' WHERE id = ?", [$profile->getId()]);
        $this->entityManager($kernel)->clear();

        self::assertTrue($this->service($kernel, FileRemoverInterface::class)->remove($view->uuid, 'board', 'b1'));
        self::assertSame(1, $this->rows($kernel));
        self::assertCount(3, $this->buckets($kernel)->objects('files', 'http://minio:9000'));
    }

    public function testExpiringLeavesTheObjectsToTheNextPurge(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $view    = $this->square($kernel);
        $remover = $this->service($kernel, FileRemoverInterface::class);
        $this->service($kernel, FileReferencesInterface::class)->sync('avatar', 'u1', '![](storage-file:' . $view->uuid . ')', ['board', 'b1']);

        self::assertFalse($remover->expire($view->uuid, 'board', 'b2'));
        $connection = $this->entityManager($kernel)->getConnection();
        $connection->beginTransaction();
        self::assertTrue($remover->expire($view->uuid, 'board', 'b1'));
        $connection->commit();
        self::assertCount(3, $this->buckets($kernel)->objects('files', 'http://minio:9000'), 'no bucket call');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM storage_kit_file_reference'));

        self::assertSame(1, $this->service($kernel, FilePurger::class)->run(new DateTimeImmutable())['purged']);
        self::assertSame([], $this->buckets($kernel)->objects('files', 'http://minio:9000'));
        self::assertNotContains(StorageAuditEvent::FILE_REMOVED, $this->audit($kernel)->actions());
        self::assertSame(StorageProfile::STATE_ACTIVE, $this->entityManager($kernel)->getRepository(StorageProfile::class)->findAll()[0]->getState());
        self::assertSame(0, $this->entityManager($kernel)->getRepository(StoredFile::class)->count([]));
    }
}
