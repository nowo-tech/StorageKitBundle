<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Download;

use Nowo\StorageKitBundle\Download\FileDelivery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function fopen;
use function fwrite;
use function is_resource;
use function rewind;
use function str_repeat;
use function strlen;

/**
 * Audit CJ L2: proxied downloads are copied in chunks and never beyond the size stored at upload.
 */
final class FileDeliveryCopyTest extends TestCase
{
    /**
     * @return resource
     */
    private function stream(string $contents)
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    public function testAnObjectOfTheStoredSizeIsCopiedInChunks(): void
    {
        $chunks = [];
        $body   = str_repeat('a', FileDelivery::CHUNK * 2 + 10);
        $stream = $this->stream($body);

        self::assertSame(strlen($body), FileDelivery::copy($stream, strlen($body), static function (string $chunk) use (&$chunks): void {
            $chunks[] = strlen($chunk);
        }));
        self::assertSame([FileDelivery::CHUNK, FileDelivery::CHUNK, 10], $chunks);
        self::assertFalse(is_resource($stream), 'closed');
    }

    public function testAShorterObjectStopsAtItsEnd(): void
    {
        self::assertSame(3, FileDelivery::copy($this->stream('abc'), 10, static function (): void {
        }));
    }

    public function testALargerObjectIsCutAtTheStoredSize(): void
    {
        $sent   = '';
        $stream = $this->stream(str_repeat('b', 100));
        try {
            FileDelivery::copy($stream, 40, static function (string $chunk) use (&$sent): void {
                $sent .= $chunk;
            });
            self::fail('a larger object was accepted');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('larger than the size recorded', $e->getMessage());
        }
        self::assertSame(40, strlen($sent));
        self::assertFalse(is_resource($stream));
    }
}
