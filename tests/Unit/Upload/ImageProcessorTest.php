<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Upload;

use Nowo\StorageKitBundle\Upload\ImageProcessor;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function strlen;

use const PHP_INT_MAX;

/**
 * Ported from Symfony Beacon (spec 183): only real PNG / JPEG / GIF / WebP content is accepted, within size and megapixels; JPEG,
 * PNG and still WebP are re-encoded (EXIF gone, JPEG turned upright); large images get a preview and a thumbnail.
 */
final class ImageProcessorTest extends TestCase
{
    public function testAPngIsReEncodedWithVariantsWhenLarge(): void
    {
        $image = (new ImageProcessor())->process(self::png(2000, 1000));

        self::assertSame('image/png', $image->original['mime']);
        self::assertSame([2000, 1000], [$image->original['width'], $image->original['height']]);
        self::assertSame(['preview', 'thumb'], array_keys($image->variants));
        self::assertSame([1600, 800], [$image->variants['preview']['width'], $image->variants['preview']['height']]);
        self::assertSame([400, 200], [$image->variants['thumb']['width'], $image->variants['thumb']['height']]);
        self::assertSame('image/png', ImageProcessor::sniff($image->variants['thumb']['bytes']));
    }

    public function testTheDecodedSizeMustFitInTheMemoryLeft(): void
    {
        $mb = 1024 ** 2;
        // 40 MP: 160 MB decoded, twice = 320 MB.
        self::assertTrue(ImageProcessor::fitsInMemory(40_000_000, '512M', 64 * $mb));
        self::assertFalse(ImageProcessor::fitsInMemory(40_000_000, '512M', 300 * $mb));
        self::assertFalse(ImageProcessor::fitsInMemory(40_000_000, '256M', 0));
        self::assertTrue(ImageProcessor::fitsInMemory(40_000_000, '1G', 0));
        self::assertFalse(ImageProcessor::fitsInMemory(1_000_000, '4096K', 0));
        self::assertFalse(ImageProcessor::fitsInMemory(1_000_000, '4194304', 0));
        self::assertTrue(ImageProcessor::fitsInMemory(PHP_INT_MAX >> 4, '-1', 0));
        self::assertTrue(ImageProcessor::fitsInMemory(1, '', 0));
    }

    public function testAnImageThatWouldNotFitInMemoryIsRefusedBeforeDecoding(): void
    {
        try {
            (new ImageProcessor(memoryLimit: '4M'))->process(self::png(1000, 1000)); // 8 MB to decode twice
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::PIXELS, $e->reason);
        }
        self::assertSame(1000, (new ImageProcessor(memoryLimit: '-1'))->process(self::png(1000, 1000))->original['width']);
    }

    public function testASmallImageHasNoVariants(): void
    {
        $image = (new ImageProcessor())->process(self::png(300, 200));

        self::assertSame([], $image->variants);
    }

    public function testAJpegLosesItsExifAndIsTurnedUpright(): void
    {
        $jpeg = $this->withExif($this->jpeg(40, 20), 6, 'GPS 41.38N 2.17E');
        self::assertSame(6, ImageProcessor::jpegOrientation($jpeg));

        $image = (new ImageProcessor())->process($jpeg);

        self::assertSame('image/jpeg', $image->original['mime']);
        self::assertSame([20, 40], [$image->original['width'], $image->original['height']]);
        self::assertStringNotContainsString('Exif', $image->original['bytes']);
        self::assertStringNotContainsString('GPS 41.38N', $image->original['bytes']);
        self::assertSame(1, ImageProcessor::jpegOrientation($image->original['bytes']));
    }

    /** @return iterable<string, array{int, array{int, int}}> */
    public static function orientations(): iterable
    {
        foreach ([1 => [40, 20], 2 => [40, 20], 3 => [40, 20], 4 => [40, 20], 5 => [20, 40], 6 => [20, 40], 7 => [20, 40], 8 => [20, 40], 9 => [40, 20]] as $orientation => $size) {
            yield 'orientation ' . $orientation => [$orientation, $size];
        }
    }

    /** @param array{int, int} $size */
    #[DataProvider('orientations')]
    public function testEveryExifOrientation(int $orientation, array $size): void
    {
        $image = (new ImageProcessor())->process($this->withExif($this->jpeg(40, 20), $orientation, 'x', bigEndian: $orientation % 2 === 0));

        self::assertSame($size, [$image->original['width'], $image->original['height']]);
    }

    public function testOrientationOfMalformedJpegs(): void
    {
        self::assertSame(1, ImageProcessor::jpegOrientation($this->jpeg(4, 4)));
        self::assertSame(1, ImageProcessor::jpegOrientation("\xFF\xD8\xFF\xE1\x00\x10Exif\x00\x00II"));
        self::assertSame(1, ImageProcessor::jpegOrientation("\xFF\xD8\xFF\xE1\x00\x16Exif\x00\x00II*\x00\xFF\x00\x00\x00\x01\x00"));
        self::assertSame(1, ImageProcessor::jpegOrientation("\xFF\xD8\xFF\xE1\x00\x16Exif\x00\x00II*\x00\x08\x00\x00\x00\x05\x00"));
        self::assertSame(1, ImageProcessor::jpegOrientation("\xFF\xD8\xFF\xDA\x00\x04"));
    }

    public function testAGifAndAnAnimatedWebpAreKeptAsTheyCame(): void
    {
        ob_start();
        imagegif(imagecreatetruecolor(3000, 10));
        $gif  = (string) ob_get_clean();
        $kept = (new ImageProcessor())->process($gif);
        self::assertSame($gif, $kept->original['bytes']);
        self::assertSame([], $kept->variants);

        $webp     = $this->webp(30, 10);
        $animated = substr($webp, 0, 12) . 'VP8X' . pack('V', 10) . "\x02" . str_repeat("\0", 3) . pack('v', 29) . "\0" . pack('v', 9) . "\0" . 'ANIM' . pack('V', 6) . str_repeat("\0", 6) . substr($webp, 12);
        $animated = substr_replace($animated, pack('V', strlen($animated) - 8), 4, 4);
        $kept     = (new ImageProcessor())->process($animated);
        self::assertSame($animated, $kept->original['bytes']);
        self::assertSame([30, 10], [$kept->original['width'], $kept->original['height']]);
    }

    public function testAStillWebpIsReEncoded(): void
    {
        $image = (new ImageProcessor())->process($this->webp(1700, 10));

        self::assertSame('image/webp', $image->original['mime']);
        self::assertSame('image/webp', $image->variants['preview']['mime']);
    }

    public function testTheCapsAndVariantsAreConfigurable(): void
    {
        $processor = new ImageProcessor(2048, 100, ['thumb' => 4]);

        self::assertSame(2048, $processor->maxBytes());
        self::assertSame(['thumb'], array_keys($processor->process(self::png(10, 5))->variants));
        try {
            $processor->process(self::png(20, 20));
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::PIXELS, $e->reason);
        }
        try {
            (new ImageProcessor(10))->process(self::png(4, 4));
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::SIZE, $e->reason);
        }
        self::assertGreaterThan(0, (new ImageProcessor())->process(self::png(10, 10))->totalBytes());
    }

    public function testAnUndecodableBodyBehindAValidHeaderIsRefused(): void
    {
        $png = self::png(10, 10);

        try {
            (new ImageProcessor())->process(substr($png, 0, 40) . str_repeat("\0", 60));
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::TYPE, $e->reason);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'empty' => ['', UploadRefused::EMPTY];
        yield 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', UploadRefused::TYPE];
        yield 'html' => ['<!doctype html><img src=x onerror=alert(1)>', UploadRefused::TYPE];
        yield 'png magic, html body' => ["\x89PNG\r\n\x1a\n<html>", UploadRefused::TYPE];
        yield 'jpeg magic, png content' => ["\xFF\xD8\xFF" . substr(self::png(2, 2), 3), UploadRefused::TYPE];
        yield 'too large' => [str_repeat('a', ImageProcessor::MAX_BYTES + 1), UploadRefused::SIZE];
        yield 'too many pixels' => [self::pngHeader(8000, 6000), UploadRefused::PIXELS];
        yield 'zero size' => [self::pngHeader(0, 10), UploadRefused::TYPE];
        yield 'header only' => [self::pngHeader(10, 10), UploadRefused::TYPE];
    }

    #[DataProvider('refusals')]
    public function testRefusals(string $bytes, string $reason): void
    {
        try {
            (new ImageProcessor())->process($bytes);
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame($reason, $e->getMessage());
        }
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height - 1, (int) imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
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

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function webp(int $width, int $height): string
    {
        ob_start();
        imagewebp(imagecreatetruecolor($width, $height));

        return (string) ob_get_clean();
    }

    /** A PNG signature and IHDR claiming the size, nothing else (getimagesize reads the header only). */
    private static function pngHeader(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height) . "\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
    }

    /** Inserts an APP1 Exif block (orientation + a comment) right after the JPEG's SOI. */
    private function withExif(string $jpeg, int $orientation, string $comment, bool $bigEndian = false): string
    {
        $s    = $bigEndian ? 'n' : 'v';
        $l    = $bigEndian ? 'N' : 'V';
        $tiff = ($bigEndian ? 'MM' : 'II') . pack($s, 42) . pack($l, 8)
            . pack($s, 2)
            . pack($s, 0x010E) . pack($s, 2) . pack($l, strlen($comment) + 1) . pack($l, 38)
            . pack($s, 0x0112) . pack($s, 3) . pack($l, 1) . pack($s, $orientation) . pack($s, 0)
            . pack($l, 0)
            . $comment . "\0";
        $app1 = "Exif\0\0" . $tiff;

        return "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }
}
