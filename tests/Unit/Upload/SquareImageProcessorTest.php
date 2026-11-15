<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Upload;

use InvalidArgumentException;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageProcessor;
use Nowo\StorageKitBundle\Upload\SquareImageProcessor;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_values;
use function crc32;
use function imagecolorallocate;
use function imagecolorat;
use function imagecreatefromstring;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagejpeg;
use function imagepng;
use function memory_get_usage;
use function ob_get_clean;
use function ob_start;
use function pack;
use function str_repeat;
use function strlen;
use function substr;

/**
 * 1.6 square images (profile pictures, logos): content-typed PNG / JPEG / still WebP only, size and pixel caps before
 * decoding, memory budget, re-encoded WebP squares (no metadata, no appended payload), polyglots and bombs refused or
 * neutralised.
 */
final class SquareImageProcessorTest extends TestCase
{
    public function testAWideImageBecomesItsCentredSquareInThreeSizes(): void
    {
        // 300×100: left third red, middle third green, right third blue — the centred square is the green part.
        $image = imagecreatetruecolor(300, 100);
        imagefilledrectangle($image, 0, 0, 99, 99, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, 100, 0, 199, 99, (int) imagecolorallocate($image, 0, 255, 0));
        imagefilledrectangle($image, 200, 0, 299, 99, (int) imagecolorallocate($image, 0, 0, 255));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $square = (new SquareImageProcessor())->process($png);

        self::assertSame(['bytes', 'mime', 'width', 'height'], array_keys($square->original));
        self::assertSame([SquareImageProcessor::MIME, 256, 256], [$square->original['mime'], $square->original['width'], $square->original['height']]);
        self::assertSame(['preview', 'thumb'], array_keys($square->variants));
        self::assertSame([128, 128], [$square->variants['preview']['width'], $square->variants['preview']['height']]);
        self::assertSame([64, 64], [$square->variants['thumb']['width'], $square->variants['thumb']['height']]);
        foreach ([$square->original, ...array_values($square->variants)] as $encoded) {
            self::assertSame('image/webp', ImageProcessor::sniff($encoded['bytes']));
        }
        $decoded = imagecreatefromstring($square->original['bytes']);
        self::assertNotFalse($decoded);
        $rgb = imagecolorat($decoded, 128, 128);
        self::assertGreaterThan(200, ($rgb >> 8) & 0xFF, 'green centre');
        self::assertLessThan(60, ($rgb >> 16) & 0xFF, 'no red');
    }

    public function testWithoutGdWebpSupportEveryImageIsRefusedCleanly(): void
    {
        // Audit QJ-L1: a GD build without WebP must refuse (`off`), never fail with an Error (HTTP 500).
        self::assertTrue(SquareImageProcessor::webpSupported(), 'the test image has GD with WebP');
        self::assertTrue((new SquareImageProcessor())->supported());
        $processor = new SquareImageProcessor(webp: false);
        self::assertFalse($processor->supported());
        try {
            $processor->process(Images::png(20, 20));
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::OFF, $e->reason);
        }
    }

    public function testAJpegIsTurnedUprightAndLosesItsMetadata(): void
    {
        $jpeg   = $this->withExif($this->jpeg(40, 20), 6, 'GPS 41.38N 2.17E');
        $square = (new SquareImageProcessor())->process($jpeg);

        self::assertSame('image/webp', $square->original['mime']);
        self::assertStringNotContainsString('Exif', $square->original['bytes']);
        self::assertStringNotContainsString('GPS', $square->original['bytes']);
    }

    public function testAStillWebpAndATinyImageAreAccepted(): void
    {
        self::assertSame(256, (new SquareImageProcessor())->process(Images::webp(30, 50))->original['width']);
        self::assertSame(64, (new SquareImageProcessor())->process(Images::png(1, 1))->variants['thumb']['width']);
    }

    public function testThePayloadOfAPolyglotIsDropped(): void
    {
        $processor = new SquareImageProcessor();
        foreach ([
            Images::png(40, 40) . '<?php system($_GET["c"]); ?><script>alert(1)</script>',
            Images::jpeg(40, 40) . "PK\x03\x04" . str_repeat('z', 200) . '<html><body onload=alert(1)>',
        ] as $polyglot) {
            $square = $processor->process($polyglot);
            foreach ([$square->original, ...array_values($square->variants)] as $encoded) {
                self::assertStringNotContainsString('<?php', $encoded['bytes']);
                self::assertStringNotContainsString('<script', $encoded['bytes']);
                self::assertStringNotContainsString('PK', $encoded['bytes']);
                self::assertStringNotContainsString('<html', $encoded['bytes']);
            }
        }
    }

    public function testABombIsRefusedFromItsHeaderBeforeDecoding(): void
    {
        // 50 000 × 50 000 claimed by a few dozen bytes: refused from the header, never decoded.
        try {
            (new SquareImageProcessor())->process(self::pngHeader(50_000, 50_000) . str_repeat("\0", 64));
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::PIXELS, $e->reason);
        }
        // Under the pixel cap but not in the memory left.
        try {
            (new SquareImageProcessor(memoryLimit: '4M'))->process(Images::png(1000, 1000));
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::PIXELS, $e->reason);
        }
        // A JPEG also budgets the progressive decoder buffer.
        $limit = (string) (memory_get_usage(true) + 11 * 1024 ** 2);
        self::assertSame(256, (new SquareImageProcessor(memoryLimit: $limit))->process(Images::png(1000, 1000))->original['width']);
        try {
            (new SquareImageProcessor(memoryLimit: $limit))->process(Images::jpeg(1000, 1000, true));
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::PIXELS, $e->reason);
        }
        // The configured pixel cap.
        try {
            (new SquareImageProcessor(maxPixels: 99))->process(Images::png(10, 10));
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::PIXELS, $e->reason);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'empty' => ['', UploadRefused::EMPTY];
        yield 'too large, checked before parsing' => [str_repeat('a', SquareImageProcessor::MAX_BYTES + 1), UploadRefused::SIZE];
        yield 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', UploadRefused::TYPE];
        yield 'svg with xml prolog' => ['<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>', UploadRefused::TYPE];
        yield 'html' => ['<!doctype html><img src=x onerror=alert(1)>', UploadRefused::TYPE];
        yield 'gif' => [Images::gif(10, 10), UploadRefused::TYPE];
        yield 'png magic, html body' => ["\x89PNG\r\n\x1a\n<html>", UploadRefused::TYPE];
        yield 'jpeg magic, png content' => ["\xFF\xD8\xFF" . substr(Images::png(2, 2), 3), UploadRefused::TYPE];
        yield 'webp magic, png content' => ['RIFF' . pack('V', 100) . 'WEBP' . substr(Images::png(2, 2), 0, 80), UploadRefused::TYPE];
        yield 'zero size' => [self::pngHeader(0, 10), UploadRefused::TYPE];
        yield 'header only' => [self::pngHeader(10, 10), UploadRefused::TYPE];
        yield 'animated webp (VP8X flag)' => ['RIFF' . pack('V', 30) . 'WEBPVP8X' . pack('V', 10) . "\x02" . str_repeat("\0", 9), UploadRefused::TYPE];
        yield 'animated webp (ANIM chunk)' => ['RIFF' . pack('V', 30) . 'WEBPVP8X' . pack('V', 10) . "\x00" . str_repeat("\0", 9) . 'ANIM', UploadRefused::TYPE];
    }

    #[DataProvider('refusals')]
    public function testRefusals(string $bytes, string $reason): void
    {
        try {
            (new SquareImageProcessor())->process($bytes);
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testTheCapsAreConfigurable(): void
    {
        $processor = new SquareImageProcessor(maxBytes: 2048, size: 128);
        self::assertSame(2048, $processor->maxBytes());
        self::assertSame(['original' => 128, 'preview' => 64, 'thumb' => 32], $processor->sides());
        self::assertSame(SquareImageProcessor::MAX_BYTES, (new SquareImageProcessor())->maxBytes());
        try {
            $processor->process(Images::png(2000, 2000) . str_repeat('x', 2048));
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::SIZE, $e->reason);
        }
    }

    public function testTheSizeIsBounded(): void
    {
        self::assertSame(64, (new SquareImageProcessor(size: 64))->sides()['original']);
        self::assertSame(1024, (new SquareImageProcessor(size: 1024))->sides()['original']);
        $this->expectException(InvalidArgumentException::class);
        new SquareImageProcessor(size: 63);
    }

    public function testTheSizeIsBoundedAbove(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SquareImageProcessor(size: 1025);
    }

    public function testAnimationDetection(): void
    {
        self::assertFalse(SquareImageProcessor::animated(Images::webp(4, 4)));
        self::assertFalse(SquareImageProcessor::animated('RIFF....WEBPVP8X'));
        self::assertTrue(SquareImageProcessor::animated('RIFF' . pack('V', 30) . 'WEBPVP8X' . pack('V', 10) . "\x12"));
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function jpeg(int $width, int $height): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor($width, $height));

        return (string) ob_get_clean();
    }

    /** A PNG signature and IHDR claiming the size, nothing else (getimagesize reads the header only). */
    private static function pngHeader(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
    }

    /** Inserts an APP1 Exif block (orientation + a comment) right after the JPEG's SOI. */
    private function withExif(string $jpeg, int $orientation, string $comment): string
    {
        $tiff = 'II' . pack('v', 42) . pack('V', 8)
            . pack('v', 2)
            . pack('v', 0x010E) . pack('v', 2) . pack('V', strlen($comment) + 1) . pack('V', 38)
            . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $orientation) . pack('v', 0)
            . pack('V', 0)
            . $comment . "\0";
        $app1 = "Exif\0\0" . $tiff;

        return "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }
}
