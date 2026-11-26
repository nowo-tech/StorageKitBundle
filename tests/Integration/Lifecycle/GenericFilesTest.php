<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Lifecycle;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Concurrency\StorageConflicts;
use Nowo\StorageKitBundle\Download\DeliverableFile;
use Nowo\StorageKitBundle\Download\FileDelivery;
use Nowo\StorageKitBundle\Download\FileDeliveryInterface;
use Nowo\StorageKitBundle\Download\FileDeliveryResponder;
use Nowo\StorageKitBundle\Download\FileNotDeliverable;
use Nowo\StorageKitBundle\Download\FileRangeDeliveryInterface;
use Nowo\StorageKitBundle\Download\FileReader;
use Nowo\StorageKitBundle\Download\FileReaderInterface;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Lifecycle\FilePurger;
use Nowo\StorageKitBundle\Lifecycle\FileRemoverInterface;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Reference\FileReferencesInterface;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Nowo\StorageKitBundle\Tests\Fixtures\ChatContext;
use Nowo\StorageKitBundle\Tests\Fixtures\PlainDelivery;
use Nowo\StorageKitBundle\Tests\Fixtures\UploadLimit;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\GrowingFileStream;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Tests\Support\StatementHook;
use Nowo\StorageKitBundle\Tests\Support\StatementHooks;
use Nowo\StorageKitBundle\Tests\Unit\Concurrency\StorageConflictsTest;
use Nowo\StorageKitBundle\Upload\FileUploader;
use Nowo\StorageKitBundle\Upload\FileUploaderInterface;
use Nowo\StorageKitBundle\Upload\FileVariantWriterInterface;
use Nowo\StorageKitBundle\Upload\GdImageThumbnailer;
use Nowo\StorageKitBundle\Upload\ImageThumbnailerInterface;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use Nowo\StorageKitBundle\Upload\StoredFileSource;
use Nowo\StorageKitBundle\Upload\StoredFileView;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Throwable;

use function array_keys;
use function array_values;
use function file_put_contents;
use function hash;
use function ob_get_clean;
use function ob_start;
use function str_repeat;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function strlen;
use function strtoupper;
use function substr;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * 2.2 generic files: uploads stored as they are into contexts that accept them, variants written by the host, the
 * server-side reader, and downloads with `Range` and a safe disposition — through the bundle's services on the test
 * database (SQLite locally; MySQL 8.4, MariaDB 11.4 and PostgreSQL in the Lifecycle CI job).
 */
final class GenericFilesTest extends KernelTestCase
{
    private const PDF = "%PDF-1.7\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n";

    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function kernel(array $config = [], bool $docs = false): StorageKitTestKernel
    {
        $kernel = $this->bootKernel($config, extra: static function (ContainerConfigurator $container) use ($docs): void {
            $services = $container->services();
            $services->set('test.chat_context', ChatContext::class)->public()->tag('nowo_storage_kit.file_context');
            if ($docs) {
                $services->set('test.docs_context', ChatContext::class)->args(['docs', true])->public()->tag('nowo_storage_kit.file_context');
            }
        });
        $this->profile($kernel);

        return $kernel;
    }

    private function source(string $bytes): StoredFileSource
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sk220');
        file_put_contents($path, $bytes);
        $this->temp[] = $path;

        return StoredFileSource::fromPath($path);
    }

    private function upload(StorageKitTestKernel $kernel, string $bytes = self::PDF, string $type = 'application/pdf', string $name = 'Report Q3.pdf', string $context = 'chat', string $id = 'c1', string $user = 'alice'): StoredFileView
    {
        return $this->service($kernel, FileUploaderInterface::class)->uploadFile(new InMemoryUser($user, null), $context, $id, $this->source($bytes), $name, $type);
    }

    private function refused(string $reason, Closure $upload): void
    {
        try {
            $upload();
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    private function rows(StorageKitTestKernel $kernel): int
    {
        return (int) $this->entityManager($kernel)->getConnection()->fetchOne('SELECT COUNT(*) FROM storage_kit_file');
    }

    /**
     * @return array<string, string>
     */
    private function objects(StorageKitTestKernel $kernel): array
    {
        return $this->buckets($kernel)->objects('files', 'http://minio:9000');
    }

    private function body(DeliverableFile $file): string
    {
        $body = '';
        $file->writeTo(static function (string $chunk) use (&$body): void {
            $body .= $chunk;
        });

        return $body;
    }

    public function testAFileIsStoredAsUploadedWithItsHashAndNoDimensions(): void
    {
        $kernel = $this->kernel();
        self::assertSame($this->service($kernel, FileUploader::class), $this->service($kernel, FileUploaderInterface::class));
        self::assertSame($this->service($kernel, FileUploader::class), $this->service($kernel, FileVariantWriterInterface::class));

        $view = $this->upload($kernel, name: "../../etc/\u{202E}fdp.exe:Report.pdf");
        self::assertSame(['application/pdf', strlen(self::PDF), 0, 0, hash('sha256', self::PDF), strlen(self::PDF), []], [$view->mimeType, $view->size, $view->width, $view->height, $view->sha256, $view->totalBytes, $view->variants]);
        self::assertSame(['chat', 'c1', 'conversation-c1', 'alice'], [$view->contextType, $view->contextId, $view->owner, $view->uploadedBy]);
        self::assertSame('fdp.exe_Report.pdf', $view->originalName, 'no path, no bidi override, no colon');
        self::assertSame([self::PDF], array_values($this->objects($kernel)));
        self::assertContains(StorageAuditEvent::FILE_UPLOADED, $this->audit($kernel)->actions());

        $file = $this->service($kernel, StoredFileRepository::class)->findInContext($view->uuid, 'chat', 'c1');
        self::assertInstanceOf(StoredFile::class, $file);
        self::assertTrue($file->isStoredAsUploaded());
        self::assertInstanceOf(DateTimeImmutable::class, $file->getOrphanedSince(), 'an orphan until something points at it');
        self::assertNull($file->servedVariant(StoredFile::VARIANT_THUMB), 'no stand-in for a missing variant');

        // A declared image is checked against its magic bytes, and stored as it came (no re-encoding).
        $png = $this->upload($kernel, Images::png(30, 20), 'image/png', 'shot.png');
        self::assertSame([0, 0, []], [$png->width, $png->height, $png->variants]);
        self::assertContains(Images::png(30, 20), $this->objects($kernel));
    }

    public function testRefusalsStoreNothing(): void
    {
        $kernel = $this->kernel(['upload' => ['files' => ['max_bytes' => 2048]], 'limits' => ['upload_limit' => UploadLimit::class]]);
        $chat   = $this->service($kernel, 'test.chat_context');
        self::assertInstanceOf(ChatContext::class, $chat);

        $this->refused(UploadRefused::CONTEXT, fn (): StoredFileView => $this->upload($kernel, context: 'board', id: 'b1'));
        $this->refused(UploadRefused::CONTEXT, fn (): StoredFileView => $this->upload($kernel, context: 'nope'));
        $this->refused(UploadRefused::CONTEXT, fn (): StoredFileView => $this->upload($kernel, id: 'c2'));
        $this->refused(UploadRefused::CONTEXT, fn (): StoredFileView => $this->upload($kernel, id: 'bad/id'));
        $this->refused(UploadRefused::CONTEXT, fn (): StoredFileView => $this->upload($kernel, id: 'C1'));
        $this->refused(UploadRefused::TYPE, fn (): StoredFileView => $this->upload($kernel, type: 'application/zip'));
        $this->refused(UploadRefused::TYPE, fn (): StoredFileView => $this->upload($kernel, type: 'Application/PDF'));
        $this->refused(UploadRefused::TYPE, fn (): StoredFileView => $this->upload($kernel, type: 'application/pdf '));
        $this->refused(UploadRefused::TYPE, fn (): StoredFileView => $this->upload($kernel, type: 'text/plain; charset=utf-8'));
        $this->refused(UploadRefused::TYPE, fn (): StoredFileView => $this->upload($kernel, self::PDF, 'image/png'));
        $this->refused(UploadRefused::EMPTY, fn (): StoredFileView => $this->upload($kernel, ''));
        $this->refused(UploadRefused::SIZE, fn (): StoredFileView => $this->upload($kernel, str_repeat('a', 2049), 'text/plain'));
        $chat->maxBytes = 10;
        $this->refused(UploadRefused::SIZE, fn (): StoredFileView => $this->upload($kernel, str_repeat('a', 11), 'text/plain'));
        $chat->maxBytes = 0;
        $this->refused(UploadRefused::SIZE, fn (): StoredFileView => $this->upload($kernel, 'a', 'text/plain'));
        $chat->maxBytes = 1024;
        $this->refused(UploadRefused::LIMIT, fn (): StoredFileView => $this->upload($kernel, id: 'c2', user: 'bob'));

        // The source must still be the file the caller described.
        $source = $this->source(self::PDF);
        $this->refused(UploadRefused::SOURCE, fn () => $this->service($kernel, FileUploaderInterface::class)->uploadFile(new InMemoryUser('alice', null), 'chat', 'c1', new StoredFileSource($source->path, 3), 'a.pdf', 'application/pdf'));
        $this->refused(UploadRefused::SOURCE, fn () => $this->service($kernel, FileUploaderInterface::class)->uploadFile(new InMemoryUser('alice', null), 'chat', 'c1', new StoredFileSource(sys_get_temp_dir(), 3), 'a.pdf', 'application/pdf'));
        $this->refused(UploadRefused::SOURCE, fn () => $this->service($kernel, FileUploaderInterface::class)->uploadFile(new InMemoryUser('alice', null), 'chat', 'c1', new StoredFileSource('/nonexistent/sk220', 3), 'a.pdf', 'application/pdf'));

        // A bucket that refuses: nothing left behind.
        $this->buckets($kernel)->failWrites = true;
        $this->refused(UploadRefused::STORAGE, fn (): StoredFileView => $this->upload($kernel));
        $this->buckets($kernel)->failWrites = false;
        self::assertSame(0, $this->rows($kernel));
        self::assertSame([], $this->objects($kernel));

        // No bucket for the context: off.
        $this->entityManager($kernel)->getConnection()->executeStatement('DELETE FROM storage_kit_profile');
        $this->service($kernel, StorageProfiles::class)->reset();
        $this->refused(UploadRefused::OFF, fn (): StoredFileView => $this->upload($kernel));
    }

    public function testASourceThatGrowsWhileItIsReadIsRefused(): void
    {
        $kernel = $this->kernel();
        stream_wrapper_register(GrowingFileStream::SCHEME, GrowingFileStream::class);
        try {
            $this->refused(UploadRefused::SOURCE, fn () => $this->service($kernel, FileUploaderInterface::class)->uploadFile(new InMemoryUser('alice', null), 'chat', 'c1', new StoredFileSource(GrowingFileStream::SCHEME . '://10', 10), 'a.txt', 'text/plain'));
        } finally {
            stream_wrapper_unregister(GrowingFileStream::SCHEME);
        }
        self::assertSame(0, $this->rows($kernel));
        self::assertSame([], $this->objects($kernel));
    }

    public function testAnInsertConflictIsRethrownAndAnObjectThatCannotBeDeletedIsLogged(): void
    {
        $kernel = $this->bootKernel(extra: static function (ContainerConfigurator $container): void {
            $services = $container->services();
            $services->set('test.chat_context', ChatContext::class)->public()->tag('nowo_storage_kit.file_context');
            $services->set(StatementHooks::class)->public();
            $services->set(StatementHook::class)->args([service(StatementHooks::class)])->tag('doctrine.middleware');
        });
        $this->profile($kernel);
        $this->service($kernel, StatementHooks::class)->onceBefore('INSERT INTO storage_kit_file', static function (): never {
            throw StorageConflictsTest::storageError(['40001', 1213]);
        });
        try {
            $this->upload($kernel);
            self::fail('the deadlock was swallowed');
        } catch (UploadRefused $e) {
            self::fail('a conflict became ' . $e->reason);
        } catch (Throwable $e) {
            self::assertTrue(StorageConflicts::isConflict($e));
        }
        self::assertSame([], $this->objects($kernel), 'the object is deleted again');

        // A bucket that refuses the clean-up too: the object stays (logged), the failure is still the conflict.
        $this->service($kernel, StatementHooks::class)->onceBefore('INSERT INTO storage_kit_file', static function (): never {
            throw StorageConflictsTest::storageError(['40001', 1213]);
        });
        $this->buckets($kernel)->failDeletes = true;
        try {
            $this->upload($kernel);
            self::fail('the deadlock was swallowed');
        } catch (Throwable $e) {
            self::assertTrue(StorageConflicts::isConflict($e));
        }
        self::assertCount(1, $this->objects($kernel));
        self::assertSame(0, $this->rows($kernel));
    }

    public function testImagesIntoAGenericContextFollowItsListAndLimit(): void
    {
        $kernel = $this->kernel();
        $chat   = $this->service($kernel, 'test.chat_context');
        self::assertInstanceOf(ChatContext::class, $chat);
        $images = $this->service($kernel, ImageUploaderInterface::class);

        self::assertSame('image/png', $images->uploadImage(new InMemoryUser('alice', null), 'chat', 'c1', Images::png(), 'a.png')->mimeType);
        $this->refused(UploadRefused::TYPE, static fn () => $images->uploadImage(new InMemoryUser('alice', null), 'chat', 'c1', Images::gif(), 'a.gif'));
        $chat->maxBytes = 10;
        $this->refused(UploadRefused::SIZE, static fn () => $images->uploadImage(new InMemoryUser('alice', null), 'chat', 'c1', Images::png(), 'a.png'));
    }

    public function testInsideAHostTransactionTheAuditWaitsForTheCommit(): void
    {
        $kernel     = $this->kernel();
        $connection = $this->entityManager($kernel)->getConnection();
        $connection->beginTransaction();
        $view = $this->upload($kernel);
        self::assertNotContains(StorageAuditEvent::FILE_UPLOADED, $this->audit($kernel)->actions());
        $connection->commit();
        $this->service($kernel, FileUploaderInterface::class)->afterCommit($view);
        self::assertContains(StorageAuditEvent::FILE_UPLOADED, $this->audit($kernel)->actions());
        self::assertSame(1, $this->rows($kernel));
    }

    public function testAThumbnailIsMadeByThePortAndAttachedAsAVariant(): void
    {
        $kernel = $this->kernel();
        $view   = $this->upload($kernel, Images::jpeg(600, 300), 'application/pdf', 'scan.pdf');
        $thumbs = $this->service($kernel, ImageThumbnailerInterface::class);
        self::assertInstanceOf(GdImageThumbnailer::class, $thumbs);

        $thumb = $thumbs->thumbnail($this->source(Images::jpeg(600, 300)), 120);
        self::assertSame(['image/jpeg', 120, 60, []], [$thumb->original['mime'], $thumb->original['width'], $thumb->original['height'], $thumb->variants]);

        $writer  = $this->service($kernel, FileVariantWriterInterface::class);
        $updated = $writer->putVariant($view->uuid, 'chat', 'c1', StoredFile::VARIANT_THUMB, $thumb->original['bytes'], 'image/jpeg');
        self::assertSame(['thumb'], array_keys($updated->variants));
        self::assertSame(['width' => 120, 'height' => 60, 'mime' => 'image/jpeg', 'size' => strlen($thumb->original['bytes'])], $updated->variants['thumb']);
        self::assertSame($view->size + strlen($thumb->original['bytes']), $updated->totalBytes);
        self::assertCount(2, $this->objects($kernel));

        // Replacing it deletes the object it replaced, never the original.
        $again = $writer->putVariant($view->uuid, 'chat', 'c1', StoredFile::VARIANT_THUMB, Images::png(40, 20), 'image/png');
        self::assertSame($view->size + strlen(Images::png(40, 20)), $again->totalBytes);
        self::assertCount(2, $this->objects($kernel));
        self::assertContains(Images::png(40, 20), $this->objects($kernel));
        $preview = $writer->putVariant($view->uuid, 'chat', 'c1', StoredFile::VARIANT_PREVIEW, Images::webp(80, 40), 'image/webp');
        self::assertSame(['thumb', 'preview'], array_keys($preview->variants));
        self::assertCount(3, $this->objects($kernel));
        self::assertSame($view->size + strlen(Images::png(40, 20)) + strlen(Images::webp(80, 40)), (int) $this->entityManager($kernel)->getConnection()->fetchOne('SELECT total_bytes FROM storage_kit_file'));

        // The removal port deletes every object of the file.
        self::assertNotNull($this->service($kernel, FileRemoverInterface::class)->remove($view->uuid, 'chat', 'c1'));
        self::assertSame([], $this->objects($kernel));
    }

    public function testAVariantIsRefusedForAnotherContextOrBadBytesAndLeavesNoObject(): void
    {
        $kernel = $this->kernel();
        $view   = $this->upload($kernel);
        $writer = $this->service($kernel, FileVariantWriterInterface::class);
        $thumb  = Images::png(20, 10);

        foreach ([
            [UploadRefused::CONTEXT, [$view->uuid, 'chat', 'C1']],
            [UploadRefused::CONTEXT, [$view->uuid, 'chat', 'c1 ']],
            [UploadRefused::CONTEXT, [$view->uuid, 'chat', 'c9']],
            [UploadRefused::CONTEXT, [$view->uuid, 'board', 'c1']],
            [UploadRefused::CONTEXT, [strtoupper($view->uuid), 'chat', 'c1']],
            [UploadRefused::CONTEXT, ['not-a-uuid', 'chat', 'c1']],
            [UploadRefused::CONTEXT, [$view->uuid, 'chat', 'bad/id']],
        ] as [$reason, [$uuid, $type, $id]]) {
            $this->refused($reason, static fn () => $writer->putVariant($uuid, $type, $id, 'thumb', $thumb, 'image/png'));
        }
        $this->refused(UploadRefused::EMPTY, static fn () => $writer->putVariant($view->uuid, 'chat', 'c1', 'thumb', '', 'image/png'));
        $this->refused(UploadRefused::TYPE, static fn () => $writer->putVariant($view->uuid, 'chat', 'c1', 'thumb', $thumb, 'image/jpeg'));
        $this->refused(UploadRefused::TYPE, static fn () => $writer->putVariant($view->uuid, 'chat', 'c1', 'thumb', self::PDF, 'application/pdf'));
        $this->refused(UploadRefused::TYPE, static fn () => $writer->putVariant($view->uuid, 'chat', 'c1', 'thumb', '<svg/>', 'image/png'));
        $this->refused(UploadRefused::SIZE, static fn () => $writer->putVariant($view->uuid, 'chat', 'c1', 'thumb', str_repeat('a', 11 * 1024 * 1024), 'image/png'));
        try {
            $writer->putVariant($view->uuid, 'chat', 'c1', 'original', $thumb, 'image/png');
            self::fail('the original was replaced');
        } catch (InvalidArgumentException) {
        }
        $this->buckets($kernel)->failWrites = true;
        $this->refused(UploadRefused::STORAGE, static fn () => $writer->putVariant($view->uuid, 'chat', 'c1', 'thumb', $thumb, 'image/png'));
        $this->buckets($kernel)->failWrites = false;

        // An expired file takes no variant (the new object is deleted again).
        self::assertNotNull($this->service($kernel, FileRemoverInterface::class)->expire($view->uuid, 'chat', 'c1'));
        $this->refused(UploadRefused::CONTEXT, static fn () => $writer->putVariant($view->uuid, 'chat', 'c1', 'thumb', $thumb, 'image/png'));
        self::assertCount(1, $this->objects($kernel), 'only the original');
    }

    public function testAFileExpiredBetweenTheLookupAndTheLockedReadTakesNoVariant(): void
    {
        $kernel = $this->bootKernel(extra: static function (ContainerConfigurator $container): void {
            $services = $container->services();
            $services->set('test.chat_context', ChatContext::class)->public()->tag('nowo_storage_kit.file_context');
            $services->set(StatementHooks::class)->public();
            $services->set(StatementHook::class)->args([service(StatementHooks::class)])->tag('doctrine.middleware');
        });
        $this->profile($kernel);
        $view       = $this->upload($kernel);
        $connection = $this->entityManager($kernel)->getConnection();
        // The removal lands after the file was looked up and its object written, just before the row is locked.
        $this->service($kernel, StatementHooks::class)->onceBefore('SELECT context_type, context_id, size, variants', static function () use ($connection): void {
            $connection->executeStatement("UPDATE storage_kit_file SET orphaned_since = '2000-01-01 00:00:00'");
        });
        $this->refused(UploadRefused::CONTEXT, fn () => $this->service($kernel, FileVariantWriterInterface::class)->putVariant($view->uuid, 'chat', 'c1', 'thumb', Images::png(), 'image/png'));
        self::assertSame([self::PDF], array_values($this->objects($kernel)), 'the new object is deleted again');
        self::assertSame('[]', (string) $connection->fetchOne('SELECT variants FROM storage_kit_file'));
        self::assertFalse($connection->isTransactionActive());
    }

    public function testTheReaderStreamsAVariantInBoundedChunks(): void
    {
        $kernel = $this->kernel();
        $bytes  = str_repeat('0123456789', 20_000);
        $view   = $this->upload($kernel, $bytes, 'text/plain', 'log.txt');
        $reader = $this->service($kernel, FileReaderInterface::class);
        self::assertInstanceOf(FileReader::class, $reader);

        $stream = $reader->open($view->uuid, 'chat', 'c1', 'original');
        try {
            self::assertSame([200_000, 'text/plain', false], [$stream->size, $stream->mediaType, $stream->eof()]);
            $first = $stream->read(10);
            self::assertSame('0123456789', $first);
            $rest = '';
            while (!$stream->eof()) {
                $rest .= $stream->read();
            }
            self::assertSame($bytes, $first . $rest);
            self::assertSame('', $stream->read());
        } finally {
            $stream->close();
        }
        self::assertTrue($stream->eof());

        $head = $reader->open($view->uuid, 'chat', 'c1', 'original', 64);
        self::assertSame([64, substr($bytes, 0, 64)], [$head->size, $head->read(1000)]);
        self::assertSame(0, $reader->open($view->uuid, 'chat', 'c1', 'original', -5)->size);

        foreach ([[$view->uuid, 'chat', 'C1', 'original'], [$view->uuid, 'chat', 'c1', 'thumb'], [$view->uuid, 'chat', 'c1', 'huge'], [strtoupper($view->uuid), 'chat', 'c1', 'original']] as [$uuid, $type, $id, $variant]) {
            try {
                $reader->open($uuid, $type, $id, $variant);
                self::fail('opened');
            } catch (FileNotDeliverable) {
            }
        }
        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE storage_kit_profile SET bucket = ''");
        $this->bundleManager($kernel)->clear();
        $this->service($kernel, StorageProfiles::class)->reset();
        try {
            $reader->open($view->uuid, 'chat', 'c1', 'original');
            self::fail('opened');
        } catch (FileNotDeliverable) {
        }
    }

    public function testDeliveryInContextServesRangesAndASafeDisposition(): void
    {
        $kernel   = $this->kernel();
        $delivery = $this->service($kernel, FileRangeDeliveryInterface::class);
        self::assertSame($this->service($kernel, FileDelivery::class), $delivery);
        $pdf = $this->upload($kernel);

        $whole = $delivery->deliverInContext($pdf->uuid, 'chat', 'c1', 'original', null, true);
        self::assertSame(200, $whole->statusCode);
        self::assertSame([
            'Content-Length'               => (string) strlen(self::PDF),
            'Content-Type'                 => 'application/pdf',
            'Content-Disposition'          => "attachment; filename=Report_Q3.pdf; filename*=utf-8''Report%20Q3.pdf",
            'X-Content-Type-Options'       => 'nosniff',
            'Content-Security-Policy'      => FileDelivery::SANDBOX_CSP,
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Accept-Ranges'                => 'bytes',
        ], $whole->headers, 'a PDF is never inline, even when asked');
        self::assertSame(self::PDF, $this->body($whole));
        self::assertSame(240, $whole->maxAge);

        $part = $delivery->deliverInContext($pdf->uuid, 'chat', 'c1', 'original', 'bytes=5-9', ttl: 60);
        self::assertSame([206, 5, 'bytes 5-9/' . strlen(self::PDF), '5', 0], [$part->statusCode, $part->size, $part->headers['Content-Range'], $part->headers['Content-Length'], $part->maxAge]);
        self::assertSame(substr(self::PDF, 5, 5), $this->body($part));
        $tail = $delivery->deliverInContext($pdf->uuid, 'chat', 'c1', 'original', 'bytes=-4', ttl: 9999);
        self::assertSame([substr(self::PDF, -4), 240], [$this->body($tail), $tail->maxAge]);
        $none = $delivery->deliverInContext($pdf->uuid, 'chat', 'c1', 'original', 'bytes=999-');
        self::assertSame([416, 'bytes */' . strlen(self::PDF), '0', ''], [$none->statusCode, $none->headers['Content-Range'], $none->headers['Content-Length'], $this->body($none)]);
        self::assertSame(200, $delivery->deliverInContext($pdf->uuid, 'chat', 'c1', 'original', 'bytes=1-2,4-5')->statusCode);

        // Audio and video play inline; active content never runs: octet-stream, attachment, `.bin`.
        $audio = $this->upload($kernel, 'ID3' . str_repeat("\0", 20), 'audio/mpeg', 'voice note.mp3');
        self::assertSame(['audio/mpeg', "inline; filename=voice_note.mp3; filename*=utf-8''voice%20note.mp3"], [
            $delivery->deliverInContext($audio->uuid, 'chat', 'c1', 'original', null, true)->headers['Content-Type'],
            $delivery->deliverInContext($audio->uuid, 'chat', 'c1', 'original', null, true)->headers['Content-Disposition'],
        ]);
        self::assertStringStartsWith('attachment', $delivery->deliverInContext($audio->uuid, 'chat', 'c1', 'original', null, false)->headers['Content-Disposition']);
        foreach ([['<svg onload="alert(1)"/>', 'image/svg+xml', 'logo.svg'], ['<script>alert(1)</script>', 'text/html', 'page.html'], ['<p/>', 'text/html', 'noext']] as [$bytes, $type, $name]) {
            $active = $delivery->deliverInContext($this->upload($kernel, $bytes, $type, $name)->uuid, 'chat', 'c1', 'original', null, true);
            self::assertSame('application/octet-stream', $active->headers['Content-Type']);
            self::assertMatchesRegularExpression('/^attachment; filename=[a-z]+\.bin$/', $active->headers['Content-Disposition']);
        }
        $raw = $this->upload($kernel, Images::png(), 'image/png', 'raw.png');
        self::assertStringStartsWith('attachment', $delivery->deliverInContext($raw->uuid, 'chat', 'c1', 'original', null, true)->headers['Content-Disposition'], 'an image stored as uploaded is not re-encoded: never inline');
        $this->service($kernel, FileVariantWriterInterface::class)->putVariant($raw->uuid, 'chat', 'c1', 'thumb', Images::png(8, 4), 'image/png');
        $thumb = $delivery->deliverInContext($raw->uuid, 'chat', 'c1', 'thumb', null, true);
        self::assertSame(['image/png', 'inline; filename=raw.png'], [$thumb->headers['Content-Type'], $thumb->headers['Content-Disposition']]);
        self::assertSame(Images::png(8, 4), $this->body($thumb));

        // Not found: another context (look-alike), an unknown variant, a variant that was not written, a missing file.
        foreach ([[$pdf->uuid, 'chat', 'C1', 'original'], [$pdf->uuid, 'chat', 'c1', 'thumb'], [$pdf->uuid, 'chat', 'c1', 'big'], ['00000000-0000-4000-8000-000000000000', 'chat', 'c1', 'original']] as [$uuid, $type, $id, $variant]) {
            try {
                $delivery->deliverInContext($uuid, $type, $id, $variant);
                self::fail('delivered');
            } catch (FileNotDeliverable) {
            }
        }
        $this->service($kernel, FileRemoverInterface::class)->expire($pdf->uuid, 'chat', 'c1');
        try {
            $delivery->deliverInContext($pdf->uuid, 'chat', 'c1', 'original');
            self::fail('an expired file was delivered');
        } catch (FileNotDeliverable) {
        }
    }

    public function testRedirectDeliveryPresignsTheServedTypeAndDisposition(): void
    {
        $kernel = $this->bootKernel([
            'profiles'         => ['main' => ['bucket' => 'files', 'access_key' => 'test-key', 'secret' => 'test-secret-not-real', 'endpoint' => 'http://minio:9000', 'delivery' => 'redirect']],
            'instance_profile' => 'main',
        ], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set('test.chat_context', ChatContext::class)->public()->tag('nowo_storage_kit.file_context');
        });
        $view     = $this->upload($kernel, '<b>x</b>', 'text/html', 'x.html');
        $redirect = $this->service($kernel, FileRangeDeliveryInterface::class)->deliverInContext($view->uuid, 'chat', 'c1', 'original', 'bytes=0-1', true, 60);
        self::assertTrue($redirect->isRedirect());
        self::assertStringContainsString('type=application%2Foctet-stream', $redirect->location());
        self::assertStringContainsString('disposition=attachment', $redirect->location());
        self::assertSame(0, $redirect->maxAge);
    }

    public function testTheBundleRouteServesReadableGenericFilesWithRangesAndNeverChatFiles(): void
    {
        $kernel = $this->kernel(docs: true);
        $chat   = $this->upload($kernel);
        $doc    = $this->upload($kernel, context: 'docs', id: 'c9');
        $video  = $this->upload($kernel, str_repeat('v', 100), 'video/mp4', 'clip.mp4', 'docs');
        $client = $this->client($kernel, 'alice');

        // `readable()` answers [] for the chat context: its files are never served by this route.
        $client->request('GET', '/storage/files/' . $chat->uuid . '/original');
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('GET', '/storage/files/' . $doc->uuid . '/original');
        $response = $client->getResponse();
        self::assertSame([200, 'application/pdf', 'bytes'], [$response->getStatusCode(), $response->headers->get('Content-Type'), $response->headers->get('Accept-Ranges')]);
        self::assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        self::assertSame(self::PDF, $client->getInternalResponse()->getContent());

        $client->request('GET', '/storage/files/' . $video->uuid . '/original', server: ['HTTP_RANGE' => 'bytes=10-19']);
        $response = $client->getResponse();
        self::assertSame([206, 'bytes 10-19/100', '10'], [$response->getStatusCode(), $response->headers->get('Content-Range'), $response->headers->get('Content-Length')]);
        $client->request('GET', '/storage/files/' . $video->uuid . '/original', server: ['HTTP_RANGE' => 'bytes=10-19', 'HTTP_IF_RANGE' => '"x"']);
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'If-Range without a validator: the whole file');
        $client->request('GET', '/storage/files/' . $video->uuid . '/original', server: ['HTTP_RANGE' => 'bytes=500-']);
        self::assertSame(416, $client->getResponse()->getStatusCode());
        $client->request('GET', '/storage/files/' . $video->uuid . '/thumb');
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'no variant was written: no stand-in');

        // A non-member reads nothing.
        $bob = $this->client($kernel, 'bob');
        $bob->request('GET', '/storage/files/' . $doc->uuid . '/original');
        self::assertSame(404, $bob->getResponse()->getStatusCode());
    }

    public function testAHostDeliveryPortWithoutRangesIsStillCalled(): void
    {
        $kernel = $this->bootKernel(extra: static function (ContainerConfigurator $container): void {
            $services = $container->services();
            $services->set('test.docs_context', ChatContext::class)->args(['docs', true])->public()->tag('nowo_storage_kit.file_context');
            $services->set('test.plain_delivery', PlainDelivery::class)->args([service('nowo_storage_kit.file_delivery')]);
            $services->alias(FileDeliveryInterface::class, 'test.plain_delivery')->public();
        });
        $this->profile($kernel);
        $doc    = $this->upload($kernel, context: 'docs');
        $client = $this->client($kernel, 'alice');
        $client->request('GET', '/storage/files/' . $doc->uuid . '/original', server: ['HTTP_RANGE' => 'bytes=0-1']);
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'the host port has no ranges: the whole file');
    }

    public function testOrphanGenericFilesArePurgedUnlessReferenced(): void
    {
        $kernel = $this->kernel();
        $kept   = $this->upload($kernel);
        $gone   = $this->upload($kernel, 'hello', 'text/plain', 'a.txt');
        $this->service($kernel, FileReferencesInterface::class)->sync('chat_attachment', 'att-1', '![](storage-file:' . $kept->uuid . ')', ['chat', 'c1']);
        $stats = $this->service($kernel, FilePurger::class)->run(new DateTimeImmutable('+30 days'));
        self::assertSame(1, $stats['purged']);
        self::assertSame([self::PDF], array_values($this->objects($kernel)));
        unset($gone);
    }

    public function testTheResponderServesPartialAndUnsatisfiableAnswers(): void
    {
        $kernel    = $this->kernel();
        $view      = $this->upload($kernel, 'abcdefghij', 'text/plain', 'a.txt');
        $delivery  = $this->service($kernel, FileRangeDeliveryInterface::class);
        $responder = new FileDeliveryResponder();

        $response = $responder->toResponse($delivery->deliverInContext($view->uuid, 'chat', 'c1', 'original', 'bytes=2-4'));
        ob_start();
        $response->sendContent();
        self::assertSame([206, 'cde'], [$response->getStatusCode(), ob_get_clean()]);
        $none = $responder->toResponse($delivery->deliverInContext($view->uuid, 'chat', 'c1', 'original', 'bytes=10-'));
        ob_start();
        $none->sendContent();
        self::assertSame([416, 'bytes */10', ''], [$none->getStatusCode(), $none->headers->get('Content-Range'), ob_get_clean()]);
    }
}
