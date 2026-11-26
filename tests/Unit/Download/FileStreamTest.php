<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Download;

use ArrayObject;
use Generator;
use LogicException;
use Nowo\StorageKitBundle\Download\FileStream;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function serialize;
use function str_repeat;
use function strlen;
use function unserialize;

/**
 * 2.2: the bounded server-side read stream.
 */
final class FileStreamTest extends TestCase
{
    public function testItReturnsTheBytesInPiecesOfTheAskedLength(): void
    {
        $stream = new FileStream(['abc', 'defg', 'h'], 8, 'text/plain');
        self::assertSame(['ab', 'cdef', 'gh', '', true], [$stream->read(2), $stream->read(4), $stream->read(), $stream->read(), $stream->eof()]);
        self::assertSame('', (new FileStream([], 0, 'text/plain'))->read(0));
    }

    public function testMoreThanTheSizeStopsTheReadAndTheRestIsNotPulled(): void
    {
        $pulled = new ArrayObject();
        $chunks = (static function () use ($pulled): Generator {
            for ($i = 0; $i < 100; ++$i) {
                $pulled->append($i);
                yield str_repeat('x', 10);
            }
        })();
        $stream = new FileStream($chunks, 15, 'application/pdf');
        self::assertSame('xxxxx', $stream->read(5));
        try {
            $stream->read(100);
            self::fail('more than the size was read');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('larger than the size', $e->getMessage());
        }
        self::assertCount(2, $pulled, 'nothing past the bound is pulled');
        self::assertTrue($stream->eof(), 'closed');
    }

    public function testAClosedStreamReleasesItsSourceAndRefusesReads(): void
    {
        $closed = new ArrayObject();
        // Only the stream holds the source (as with a bucket read): closing it releases the source.
        $stream = new FileStream((static function () use ($closed): Generator {
            try {
                yield 'a';
                yield 'b';
            } finally {
                $closed->append(true);
            }
        })(), 2, 'text/plain');
        self::assertSame('a', $stream->read(1));
        $stream->close();
        $stream->close();
        self::assertCount(1, $closed, 'the bucket response / handle is released at once');
        $this->expectException(LogicException::class);
        $stream->read();
    }

    public function testItIsNeitherClonedNorSerialized(): void
    {
        $stream  = new FileStream(['a'], 1, 'text/plain');
        $refused = [];
        foreach ([static fn (): FileStream => clone $stream, static fn (): string => serialize($stream), static fn (): mixed => unserialize('O:' . strlen(FileStream::class) . ':"' . FileStream::class . '":0:{}')] as $call) {
            try {
                $call();
                self::fail('allowed');
            } catch (LogicException) {
                $refused[] = true;
            }
        }
        self::assertCount(3, $refused);
    }
}
