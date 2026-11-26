<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Lifecycle;

use Nowo\StorageKitBundle\Download\FileNotDeliverable;
use Nowo\StorageKitBundle\Download\FileRangeDeliveryInterface;
use Nowo\StorageKitBundle\Download\FileReaderInterface;
use Nowo\StorageKitBundle\Lifecycle\FileRemoverInterface;
use Nowo\StorageKitBundle\Tests\Fixtures\ChatContext;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\FileUploaderInterface;
use Nowo\StorageKitBundle\Upload\FileVariantWriterInterface;
use Nowo\StorageKitBundle\Upload\ImageThumbnailerInterface;
use Nowo\StorageKitBundle\Upload\StoredFileSource;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function file_put_contents;
use function memory_get_usage;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * 2.2, FrankenPHP worker mode with kernel reset false (REQ-RUNTIME-002): one kept-alive kernel serves 300 rounds of
 * generic file work for two people in two conversations — upload, thumbnail, variant, server-side read, ranged
 * delivery, a removal half-way — with the request boundary but no services resetter. No round sees another's file,
 * nothing leaks between them and memory stays flat.
 */
final class GenericFilesWorkerTest extends KernelTestCase
{
    public function testAKeptAliveKernelServesGenericFilesWithoutLeaking(): void
    {
        $kernel = $this->bootKernel(extra: static function (ContainerConfigurator $container): void {
            $container->services()->set('test.chat_context', ChatContext::class)->public()->tag('nowo_storage_kit.file_context');
        });
        $this->profile($kernel);
        $uploader = $this->service($kernel, FileUploaderInterface::class);
        $writer   = $this->service($kernel, FileVariantWriterInterface::class);
        $thumbs   = $this->service($kernel, ImageThumbnailerInterface::class);
        $reader   = $this->service($kernel, FileReaderInterface::class);
        $delivery = $this->service($kernel, FileRangeDeliveryInterface::class);
        $remover  = $this->service($kernel, FileRemoverInterface::class);
        $image    = (string) tempnam(sys_get_temp_dir(), 'sk220w');
        file_put_contents($image, Images::png(64, 32));
        $text = (string) tempnam(sys_get_temp_dir(), 'sk220w');
        file_put_contents($text, 'hello world');

        $baseline = 0;
        try {
            for ($i = 0; $i < 300; ++$i) {
                $request     = Request::create('/no-such-page-' . $i);
                $response    = $kernel->handle($request);
                [$user, $id] = $i % 2 === 0 ? ['alice', 'c9'] : ['bob', 'c2'];
                $view        = $uploader->uploadFile(new InMemoryUser($user, null), 'chat', $id, StoredFileSource::fromPath($i % 3 === 0 ? $image : $text), 'f' . $i, $i % 3 === 0 ? 'image/png' : 'text/plain');
                if ($i % 3 === 0) {
                    $thumb = $thumbs->thumbnail(StoredFileSource::fromPath($image), 32);
                    $writer->putVariant($view->uuid, 'chat', $id, 'thumb', $thumb->original['bytes'], $thumb->original['mime']);
                }
                $stream = $reader->open($view->uuid, 'chat', $id, 'original');
                try {
                    self::assertSame($view->size, strlen($stream->read($view->size + 1)));
                } finally {
                    $stream->close();
                }
                $part = $delivery->deliverInContext($view->uuid, 'chat', $id, 'original', 'bytes=0-1');
                self::assertSame(206, $part->statusCode);
                // The other person's conversation never serves this file.
                try {
                    $delivery->deliverInContext($view->uuid, 'chat', $id === 'c9' ? 'c2' : 'c9', 'original');
                    self::fail('served in another context');
                } catch (FileNotDeliverable) {
                }
                if ($i === 150) {
                    self::assertNotNull($remover->remove($view->uuid, 'chat', $id));
                }
                $kernel->terminate($request, $response);
                unset($stream, $part, $view, $thumb);
                if ($i === 99) {
                    $baseline = memory_get_usage();
                }
            }
        } finally {
            @unlink($image);
            @unlink($text);
        }
        self::assertLessThan(3 * 1024 * 1024, memory_get_usage() - $baseline, 'no per-request growth');
        self::assertSame(299, (int) $this->entityManager($kernel)->getConnection()->fetchOne('SELECT COUNT(*) FROM storage_kit_file'));
    }
}
