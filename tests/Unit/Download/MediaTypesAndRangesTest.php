<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Download;

use Nowo\StorageKitBundle\Download\ByteRange;
use Nowo\StorageKitBundle\Download\DeliverableFile;
use Nowo\StorageKitBundle\Download\FileDelivery;
use Nowo\StorageKitBundle\Download\MediaTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * 2.2: the media type rules of generic files, the `Range` parser and the generic file disposition.
 */
final class MediaTypesAndRangesTest extends TestCase
{
    public function testStorableTypesAreBareLowerCaseTypes(): void
    {
        foreach (['application/pdf', 'text/plain', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'audio/x-wav', 'image/svg+xml'] as $type) {
            self::assertTrue(MediaTypes::isValid($type), $type);
        }
        foreach (['', 'pdf', 'Application/PDF', 'text/plain; charset=utf-8', ' text/plain', 'text/plain ', 'text/', '/plain', 'a/b/c', "text/pla\u{0131}n", 'a/' . str_repeat('b', 126)] as $type) {
            self::assertFalse(MediaTypes::isValid($type), $type);
        }
    }

    public function testActiveContentIsNeverServedAsItself(): void
    {
        foreach (['text/html', 'image/svg+xml', 'application/xhtml+xml', 'application/rss+xml', 'text/javascript', 'text/xml', 'Text/HTML'] as $type) {
            self::assertTrue(MediaTypes::isActive($type), $type);
            self::assertSame(MediaTypes::OCTET_STREAM, MediaTypes::served($type));
            self::assertFalse(MediaTypes::inlineSafe($type, true));
        }
        self::assertSame('application/pdf', MediaTypes::served('application/pdf'));
        self::assertFalse(MediaTypes::isActive('application/pdf'));
    }

    public function testOnlyCheckedImagesAndMediaAreInlineSafe(): void
    {
        self::assertTrue(MediaTypes::inlineSafe('image/png', true));
        self::assertFalse(MediaTypes::inlineSafe('image/png', false), 'an image stored as uploaded is not re-encoded');
        self::assertTrue(MediaTypes::inlineSafe('video/mp4', false));
        self::assertTrue(MediaTypes::inlineSafe('audio/mpeg', false));
        self::assertFalse(MediaTypes::inlineSafe('application/pdf', true));
        self::assertFalse(MediaTypes::inlineSafe('image/tiff', true));
        self::assertTrue(MediaTypes::isRasterImage('image/webp'));
        self::assertFalse(MediaTypes::isInlineMedia('video/x-msvideo'));
    }

    /**
     * @return iterable<string, array{string|null, int, array{int, int}|false|null}>
     */
    public static function ranges(): iterable
    {
        yield 'none' => [null, 100, null];
        yield 'first ten' => ['bytes=0-9', 100, [0, 9]];
        yield 'open end' => ['bytes=90-', 100, [90, 99]];
        yield 'end past the size' => ['bytes=90-500', 100, [90, 99]];
        yield 'suffix' => ['bytes=-10', 100, [90, 99]];
        yield 'suffix past start' => ['bytes=-500', 100, [0, 99]];
        yield 'zero suffix' => ['bytes=-0', 100, false];
        yield 'start past the end' => ['bytes=100-', 100, false];
        yield 'reversed' => ['bytes=9-0', 100, null];
        yield 'several' => ['bytes=0-1,5-6', 100, null];
        yield 'other unit' => ['items=0-1', 100, null];
        yield 'empty' => ['bytes=-', 100, null];
        yield 'garbage' => ['bytes=a-b', 100, null];
        yield 'spaces' => ['bytes= 0-1', 100, null];
        yield 'overflow' => ['bytes=0-99999999999999999999', 100, null];
        yield 'too long' => ['bytes=0-1' . str_repeat(' ', 64), 100, null];
        yield 'empty object' => ['bytes=0-1', 0, null];
    }

    /**
     * @param array{int, int}|false|null $expected
     */
    #[DataProvider('ranges')]
    public function testTheRangeHeaderIsParsedStrictly(?string $header, int $size, array|false|null $expected): void
    {
        $range = ByteRange::parse($header, $size);
        if ($expected === null || $expected === false) {
            self::assertSame($expected, $range);

            return;
        }
        self::assertInstanceOf(ByteRange::class, $range);
        self::assertSame($expected, [$range->start, $range->end]);
        self::assertSame($expected[1] - $expected[0] + 1, $range->length());
        self::assertSame('bytes ' . $expected[0] . '-' . $expected[1] . '/' . $size, $range->contentRange($size));
    }

    public function testPartialAndUnsatisfiableDeliveries(): void
    {
        $range = ByteRange::parse('bytes=2-4', 10);
        self::assertInstanceOf(ByteRange::class, $range);
        $part = DeliverableFile::partial(static fn (): iterable => ['cde'], $range, 10, 60, ['Content-Type' => 'text/plain']);
        self::assertSame([206, 3, false], [$part->statusCode, $part->size, $part->isRedirect()]);
        self::assertSame(['Content-Type' => 'text/plain', 'Content-Length' => '3', 'Content-Range' => 'bytes 2-4/10'], $part->headers);
        $sent = '';
        self::assertSame(3, $part->writeTo(static function (string $chunk) use (&$sent): void {
            $sent .= $chunk;
        }));
        self::assertSame('cde', $sent);

        $none = DeliverableFile::unsatisfiable(10, 60);
        self::assertSame([416, 0, ['Content-Length' => '0', 'Content-Range' => 'bytes */10']], [$none->statusCode, $none->size, $none->headers]);
        self::assertSame(0, $none->writeTo(static function (): never {
            self::fail('a 416 has no body');
        }));
    }

    public function testAGenericFileIsOfferedUnderASafeName(): void
    {
        self::assertSame("attachment; filename=Report_Q3.pdf; filename*=utf-8''Report%20Q3.pdf", FileDelivery::fileDisposition('attachment', 'Report Q3.pdf', 'application/pdf'));
        self::assertSame("attachment; filename=file.txt; filename*=utf-8''%C3%BC%C3%B1%C3%AF.txt", FileDelivery::fileDisposition('attachment', 'üñï.txt', 'text/plain'));
        self::assertSame('attachment; filename=README', FileDelivery::fileDisposition('attachment', 'README', 'text/plain'));
        self::assertSame("attachment; filename=a.bin; filename*=utf-8''a.%C3%A9x%C3%A9", FileDelivery::fileDisposition('attachment', 'a.éxé', 'application/octet-stream'));
        self::assertSame('attachment; filename=page.bin', FileDelivery::fileDisposition('attachment', 'page.html', 'text/html'));
        self::assertSame("inline; filename=say_hi.mp3; filename*=utf-8''say%20_hi_.mp3", FileDelivery::fileDisposition('inline', 'say "hi".mp3', 'audio/mpeg'));
    }
}
