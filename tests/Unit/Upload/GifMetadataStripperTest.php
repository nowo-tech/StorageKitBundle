<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Upload;

use GdImage;
use Nowo\StorageKitBundle\Tests\Support\AnimatedImages;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\GifMetadataStripper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function chr;
use function count;
use function getimagesizefromstring;
use function imagecreatefromstring;
use function in_array;
use function ord;
use function str_repeat;
use function strlen;
use function substr;

/**
 * 2.2.1 (SEC-INFO-GIF-METADATA): a GIF is rebuilt without its comments, plain text, XMP / ICC application extensions
 * and trailing bytes; the frames are kept byte for byte; malformed input gives null.
 */
final class GifMetadataStripperTest extends TestCase
{
    public function testEveryPlantedMetadataIsGone(): void
    {
        $gif      = AnimatedImages::gifWithMetadata();
        $stripped = (new GifMetadataStripper())->strip($gif);

        self::assertNotNull($stripped);
        self::assertStringContainsString(AnimatedImages::SECRET, $gif);
        self::assertStringNotContainsString(AnimatedImages::SECRET, $stripped);
        self::assertStringNotContainsString('XMP DataXMP', $stripped);
        self::assertStringNotContainsString('ICCRGBG1012', $stripped);
        self::assertStringNotContainsString('comment', $stripped);
        self::assertStringNotContainsString('trailing', $stripped);
        self::assertStringEndsWith("\x00\x3B", $stripped);
        // Only the loop extension, the graphic controls and the images are left.
        self::assertSame(['loop', 'gce', 'image', 'gce', 'image', 'gce', 'image'], array_map(static fn (array $block): string => $block[0], self::blocks($stripped)));
    }

    public function testTheAnimationIsKeptFrameForFrame(): void
    {
        $gif      = AnimatedImages::gifWithMetadata(5);
        $stripped = (string) (new GifMetadataStripper())->strip($gif);

        $frames = static fn (string $bytes): array => array_values(array_filter(self::blocks($bytes), static fn (array $block): bool => $block[0] === 'image' || $block[0] === 'gce'));
        self::assertSame($frames($gif), $frames($stripped));
        self::assertCount(5, array_filter(self::blocks($stripped), static fn (array $block): bool => $block[0] === 'image'));
        // Header, logical screen and global table untouched; the loop count stays.
        self::assertSame(substr($gif, 0, 19), substr($stripped, 0, 19));
        self::assertSame(AnimatedImages::loop(0), substr($stripped, 19, 19));
        // Still a GIF that PHP and GD read.
        self::assertSame([4, 4], array_slice((array) getimagesizefromstring($stripped), 0, 2));
        self::assertInstanceOf(GdImage::class, imagecreatefromstring($stripped));
    }

    public function testACleanGifIsUnchangedAndStrippingIsIdempotent(): void
    {
        $stripper = new GifMetadataStripper();
        $clean    = Images::gif(30, 20);
        self::assertSame($clean, $stripper->strip($clean));

        $once = (string) $stripper->strip(AnimatedImages::gifWithMetadata());
        self::assertSame($once, $stripper->strip($once));
    }

    public function testGraphicControlAndLoopExtensionsAreRewrittenCanonically(): void
    {
        $head  = AnimatedImages::gifHead();
        $image = AnimatedImages::gifImageBlock();
        // Reserved bits set, and a transparent index without the transparency flag: both cleared.
        $gif = $head . AnimatedImages::gce(0xE0 | 0x08, 7, 0x41) . $image . AnimatedImages::gce(0x09, 7, 0x01) . $image . "\x3B";
        self::assertSame($head . AnimatedImages::gce(0x08, 7, 0) . $image . AnimatedImages::gce(0x09, 7, 1) . $image . "\x3B", (new GifMetadataStripper())->strip($gif));

        // ANIMEXTS1.0 kept; a loop extension whose first sub-block is not the loop, an unknown application and an
        // unknown extension label are dropped.
        $gif = $head . AnimatedImages::loop(3, 'ANIMEXTS1.0')
            . "\x21\xFF\x0BNETSCAPE2.0\x03\x02\x00\x10\x00"
            . "\x21\xFF\x0BNETSCAPE2.0\x02\x01\x00\x00"
            . AnimatedImages::application('MGK8BIM0000', 'photoshop ' . AnimatedImages::SECRET)
            . AnimatedImages::extension(0x99, 'unknown ' . AnimatedImages::SECRET)
            . $image . "\x3B";
        self::assertSame($head . AnimatedImages::loop(3, 'ANIMEXTS1.0') . $image . "\x3B", (new GifMetadataStripper())->strip($gif));
    }

    public function testBrowserTolerancesOf220AreKept(): void
    {
        $stripper = new GifMetadataStripper();
        $head     = AnimatedImages::gifHead();
        $image    = AnimatedImages::gifImageBlock();

        // No trailer, the file ending on a block boundary: the trailer is added.
        self::assertSame($head . $image . "\x3B", $stripper->strip($head . $image));
        self::assertSame($head . $image . "\x3B", $stripper->strip($head . $image . AnimatedImages::extension(0xFE, AnimatedImages::SECRET)));
        // A stray byte between blocks ends the file, as in Chromium and Firefox: what follows is dropped.
        self::assertSame($head . $image . "\x3B", $stripper->strip($head . $image . "\x00" . $image . AnimatedImages::SECRET));
        // An application extension whose first block is not 11 bytes is dropped, not refused.
        self::assertSame($head . $image . "\x3B", $stripper->strip($head . "\x21\xFF\x0ANETSCAPE2.\x03\x01\x00\x00\x00" . $image . "\x3B"));
        // LZW minimum code sizes 1 and 11 (decoded by browsers) are accepted.
        $desc = "\x2C\x00\x00\x00\x00\x04\x00\x04\x00\x00";
        foreach (["\x01", "\x0B"] as $codeSize) {
            $gif = $head . $desc . $codeSize . "\x02\x44\x01\x00\x3B";
            self::assertSame($gif, $stripper->strip($gif));
        }
    }

    public function testSmallCovertFieldsAreCanonical(): void
    {
        $stripper = new GifMetadataStripper();
        $head     = AnimatedImages::gifHead();
        $image    = AnimatedImages::gifImageBlock();

        // Only the last graphic control before an image is kept; one not followed by an image is dropped.
        $gif = $head . AnimatedImages::gce(0x04, 1) . AnimatedImages::gce(0x04, 2) . AnimatedImages::gce(0x08, 3) . $image . AnimatedImages::gce(0x04, 4) . "\x3B";
        self::assertSame($head . AnimatedImages::gce(0x08, 3) . $image . "\x3B", $stripper->strip($gif));

        // Only the first loop extension is kept.
        $gif = $head . AnimatedImages::loop(2) . AnimatedImages::loop(7) . AnimatedImages::loop(9, 'ANIMEXTS1.0') . $image . "\x3B";
        self::assertSame($head . AnimatedImages::loop(2) . $image . "\x3B", $stripper->strip($gif));

        // The pixel aspect byte and the descriptor reserved bits are cleared.
        $odd = substr($head, 0, 12) . "\x31" . substr($head, 13);
        $gif = $odd . substr($image, 0, 9) . chr(ord($image[9]) | 0x18) . substr($image, 10) . "\x3B";
        self::assertSame($head . $image . "\x3B", $stripper->strip($gif));
    }

    public function testALocalColourTableIsKept(): void
    {
        $image = AnimatedImages::gifImageBlock();
        // Same image with a 2-colour local table.
        $local = substr($image, 0, 9) . chr(ord($image[9]) | 0x80) . "\x10\x20\x30\x40\x50\x60" . substr($image, 10);
        $gif   = AnimatedImages::gifHead() . $local . "\x3B";
        self::assertSame($gif, (new GifMetadataStripper())->strip($gif));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformed(): iterable
    {
        $head  = AnimatedImages::gifHead();
        $image = AnimatedImages::gifImageBlock();
        $desc  = "\x2C\x00\x00\x00\x00\x04\x00\x04\x00\x00";

        yield 'too short' => ['GIF89a'];
        yield 'not a GIF' => ['GIF90a' . substr($head, 6) . $image . "\x3B"];
        yield 'global table past the end' => ['GIF89a' . "\x04\x00\x04\x00\x87\x00\x00" . str_repeat("\0", 30)];
        yield 'truncated image data' => [$head . substr($image, 0, -3)];
        yield 'trailer without image' => [$head . "\x3B"];
        yield 'truncated descriptor' => [$head . "\x2C\x00\x00"];
        yield 'zero-width frame' => [$head . "\x2C\x00\x00\x00\x00\x00\x00\x04\x00\x00\x02\x02\x44\x01\x00\x3B"];
        yield 'zero-height frame' => [$head . "\x2C\x00\x00\x00\x00\x04\x00\x00\x00\x00\x02\x02\x44\x01\x00\x3B"];
        yield 'frame above max pixels' => [$head . "\x2C\x00\x00\x00\x00\xFF\xFF\xFF\xFF\x00\x02\x02\x44\x01\x00\x3B"];
        yield 'local table past the end' => [$head . "\x2C\x00\x00\x00\x00\x04\x00\x04\x00\x87\x00\x00"];
        yield 'descriptor at the very end' => [$head . $desc];
        yield 'LZW code size 0' => [$head . $desc . "\x00\x02\x44\x01\x00\x3B"];
        yield 'LZW code size 12' => [$head . $desc . "\x0C\x02\x44\x01\x00\x3B"];
        yield 'sub-block past the end' => [$head . $desc . "\x02\xFF\x44\x01"];
        yield 'stray byte before any image' => [$head . "\x00" . $image . "\x3B"];
        yield 'image cut after its last sub-block size' => [$head . substr($image, 0, -1)];
        yield 'extension cut after an image' => [$head . $image . "\x21\xFE\x05abc"];
        yield 'extension introducer at the end' => [$head . "\x21"];
        yield 'extension past the end' => [$head . "\x21\xFE\x20comment"];
        yield 'graphic control of 3 bytes' => [$head . "\x21\xF9\x03\x00\x00\x00\x00" . $image . "\x3B"];
        yield 'graphic control of 2 sub-blocks' => [$head . "\x21\xF9\x04\x00\x00\x00\x00\x01\x00\x00" . $image . "\x3B"];
    }

    #[DataProvider('malformed')]
    public function testMalformedInputGivesNull(string $gif): void
    {
        self::assertNull((new GifMetadataStripper())->strip($gif));
    }

    public function testTheBoundsAreEnforced(): void
    {
        $gif = AnimatedImages::gifWithMetadata(4);

        self::assertNull((new GifMetadataStripper(maxBytes: strlen($gif) - 1))->strip($gif));
        self::assertNotNull((new GifMetadataStripper(maxBytes: strlen($gif)))->strip($gif));
        self::assertNull((new GifMetadataStripper(maxFrames: 3))->strip($gif));
        self::assertNotNull((new GifMetadataStripper(maxFrames: 4))->strip($gif));
        self::assertNull((new GifMetadataStripper(maxPixels: 15))->strip($gif));
        self::assertNotNull((new GifMetadataStripper(maxPixels: 16))->strip($gif));
    }

    public function testItIsStatelessAcrossCallsForWorkerMode(): void
    {
        $class = new ReflectionClass(GifMetadataStripper::class);
        self::assertTrue($class->isReadOnly());
        self::assertSame([], $class->getStaticProperties());

        // One long-lived instance (FrankenPHP worker, kernel not reset): malformed and valid inputs interleaved give
        // what a fresh instance gives.
        $shared = new GifMetadataStripper();
        $inputs = [AnimatedImages::gifWithMetadata(2), AnimatedImages::gifHead() . "\x21", AnimatedImages::gifWithMetadata(3), Images::gif(5, 5), AnimatedImages::gifWithMetadata(2)];
        foreach ($inputs as $input) {
            self::assertSame((new GifMetadataStripper())->strip($input), $shared->strip($input));
        }
    }

    /**
     * An independent walk over the blocks after the global table: [kind, raw bytes] with kind `image`, `gce`, `loop`,
     * `ext`; stops at the trailer.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function blocks(string $gif): array
    {
        $packed = ord($gif[10]);
        $offset = 13 + (($packed & 0x80) !== 0 ? 3 << (($packed & 0x07) + 1) : 0);
        $blocks = [];
        while ($gif[$offset] !== "\x3B") {
            $start = $offset;
            if ($gif[$offset] === "\x2C") {
                $local = ord($gif[$offset + 9]);
                $offset += 10 + (($local & 0x80) !== 0 ? 3 << (($local & 0x07) + 1) : 0) + 1;
                $kind = 'image';
            } else {
                $label = ord($gif[$offset + 1]);
                $kind  = $label === 0xF9 ? 'gce' : ($label === 0xFF && in_array(substr($gif, $offset + 3, 11), ['NETSCAPE2.0', 'ANIMEXTS1.0'], true) ? 'loop' : 'ext');
                $offset += 2;
            }
            while (($size = ord($gif[$offset])) !== 0) {
                $offset += 1 + $size;
            }
            ++$offset;
            $blocks[] = [$kind, substr($gif, $start, $offset - $start)];
        }
        self::assertGreaterThan(0, count($blocks));

        return $blocks;
    }
}
