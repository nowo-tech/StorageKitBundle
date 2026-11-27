<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Upload;

use Nowo\StorageKitBundle\Tests\Support\AnimatedImages;
use Nowo\StorageKitBundle\Upload\WebpMetadataStripper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function array_column;
use function array_slice;
use function chr;
use function getimagesizefromstring;
use function ord;
use function pack;
use function str_repeat;
use function strlen;
use function substr;
use function unpack;

/**
 * 2.2.1 (SEC-INFO-GIF-METADATA): an animated WebP is rebuilt without its EXIF, XMP, ICC and unknown chunks (inside
 * frames too) and without bytes after the RIFF size; the frames are kept byte for byte; malformed input gives null.
 */
final class WebpMetadataStripperTest extends TestCase
{
    public function testEveryPlantedMetadataIsGone(): void
    {
        $webp     = AnimatedImages::webpWithMetadata();
        $stripped = (new WebpMetadataStripper())->strip($webp);

        self::assertNotNull($stripped);
        self::assertStringContainsString(AnimatedImages::SECRET, $webp);
        self::assertStringNotContainsString(AnimatedImages::SECRET, $stripped);
        self::assertSame(['VP8X', 'ANIM', 'ANMF', 'ANMF', 'ANMF'], array_column(self::chunks($stripped, 12, strlen($stripped)), 0));
        // RIFF size fixed, VP8X keeps only alpha + animation, reserved bytes zeroed, canvas kept.
        self::assertSame(strlen($stripped) - 8, self::long($stripped, 4));
        self::assertSame(0x12, ord($stripped[20]));
        self::assertSame("\0\0\0", substr($stripped, 21, 3));
        self::assertSame(substr($webp, 24, 6), substr($stripped, 24, 6));
        self::assertSame([4, 4], array_slice((array) getimagesizefromstring($stripped), 0, 2));
    }

    public function testTheAnimationIsKeptFrameForFrame(): void
    {
        $webp     = AnimatedImages::webpWithMetadata(4);
        $stripped = (string) (new WebpMetadataStripper())->strip($webp);

        $frames = static function (string $bytes): array {
            $frames = [];
            foreach (self::chunks($bytes, 12, 8 + self::long($bytes, 4)) as [$fourcc, $data]) {
                if ($fourcc === 'ANMF') {
                    // Header + the bitstream sub-chunks (unknown ones left out).
                    $kept = substr($data, 0, 16);
                    foreach (self::chunks($data, 16, strlen($data)) as [$inner, , $raw]) {
                        $kept .= $inner === 'XYZW' ? '' : $raw;
                    }
                    $frames[] = $kept;
                }
            }

            return $frames;
        };
        self::assertCount(4, $frames($stripped));
        self::assertSame($frames($webp), $frames($stripped));
        // ANIM kept as it is; odd-sized chunks keep their padding byte.
        self::assertStringContainsString(AnimatedImages::chunk('ANIM', "\0\0\0\0\0\0"), $stripped);
        self::assertStringContainsString(AnimatedImages::chunk('VP8 ', 'bitstream-0x'), $stripped);
        self::assertSame(0, strlen($stripped) % 2);
    }

    public function testACleanAnimationIsUnchangedAndStrippingIsIdempotent(): void
    {
        $stripper = new WebpMetadataStripper();
        $clean    = AnimatedImages::riff(
            AnimatedImages::vp8x(0x12),
            AnimatedImages::chunk('ANIM', "\0\0\0\0\0\0"),
            AnimatedImages::anmf(4, 4, AnimatedImages::chunk('ALPH', 'a'), AnimatedImages::chunk('VP8 ', 'vp8-data')),
            AnimatedImages::anmfAt(2, 2, 2, 2, 0x01, AnimatedImages::chunk('VP8L', 'top')),
        );
        self::assertSame($clean, $stripper->strip($clean));

        $once = (string) $stripper->strip(AnimatedImages::webpWithMetadata());
        self::assertSame($once, $stripper->strip($once));
    }

    public function testAnAlphNextToVp8lAndReservedFrameFlagsAreDropped(): void
    {
        $head = [AnimatedImages::vp8x(0x02), AnimatedImages::chunk('ANIM', "\0\0\0\0\0\0")];
        $webp = AnimatedImages::riff(...$head, ...[AnimatedImages::anmfAt(0, 0, 4, 4, 0xFE, AnimatedImages::chunk('ALPH', AnimatedImages::SECRET), AnimatedImages::chunk('VP8L', 'lossless'))]);

        self::assertSame(
            AnimatedImages::riff(...$head, ...[AnimatedImages::anmfAt(0, 0, 4, 4, 0x02, AnimatedImages::chunk('VP8L', 'lossless'))]),
            (new WebpMetadataStripper())->strip($webp),
        );
    }

    public function testAnimatedMeansAFirstVp8xWithTheAnimationFlag(): void
    {
        self::assertTrue(WebpMetadataStripper::animated(AnimatedImages::riff(AnimatedImages::vp8x(0x02))));
        self::assertFalse(WebpMetadataStripper::animated(AnimatedImages::riff(AnimatedImages::vp8x(0x10))));
        self::assertFalse(WebpMetadataStripper::animated(AnimatedImages::riff(AnimatedImages::chunk('VP8L', 'ANIM ANIM ANIM'))));
        self::assertFalse(WebpMetadataStripper::animated('RIFF....WEBPVP8X'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformed(): iterable
    {
        $vp8x  = AnimatedImages::vp8x(0x02);
        $anim  = AnimatedImages::chunk('ANIM', "\0\0\0\0\0\0");
        $frame = AnimatedImages::anmf(4, 4, AnimatedImages::chunk('VP8L', 'data'));
        $valid = AnimatedImages::riff($vp8x, $anim, $frame);

        yield 'too short' => [substr($valid, 0, 29)];
        yield 'not RIFF' => ['RIFX' . substr($valid, 4)];
        yield 'not WEBP' => [substr($valid, 0, 8) . 'WEBQ' . substr($valid, 12)];
        yield 'not animated' => [AnimatedImages::riff(AnimatedImages::vp8x(0x10), $anim, $frame)];
        yield 'RIFF size past the end' => [substr($valid, 0, 4) . pack('V', strlen($valid)) . substr($valid, 8)];
        yield 'RIFF size too small' => [substr($valid, 0, 4) . pack('V', 4) . substr($valid, 8)];
        yield 'truncated chunk header' => [AnimatedImages::riff($vp8x, 'ANIM')];
        yield 'chunk past the end' => [AnimatedImages::riff($vp8x, 'ANIM' . pack('V', 600) . "\0\0\0\0\0\0")];
        yield 'missing padding' => [AnimatedImages::riff($vp8x, $anim, $frame, 'XYZW' . pack('V', 3) . 'abc')];
        // ZCU-L1: what libwebp's demuxer refuses or never decodes.
        yield 'VP8X only' => [AnimatedImages::riff($vp8x)];
        yield 'ANIM without frame' => [AnimatedImages::riff($vp8x, $anim)];
        yield 'ANMF without ANIM' => [AnimatedImages::riff($vp8x, $frame)];
        yield 'ANMF before ANIM' => [AnimatedImages::riff($vp8x, $frame, $anim, $frame)];
        yield 'second ANIM' => [AnimatedImages::riff($vp8x, $anim, $anim, $frame)];
        yield 'top-level VP8L next to ANMF' => [AnimatedImages::riff($vp8x, $anim, $frame, AnimatedImages::chunk('VP8L', 'payload'))];
        yield 'top-level VP8 next to ANMF' => [AnimatedImages::riff($vp8x, $anim, AnimatedImages::chunk('VP8 ', 'payload'), $frame)];
        yield 'top-level ALPH next to ANMF' => [AnimatedImages::riff($vp8x, $anim, $frame, AnimatedImages::chunk('ALPH', 'payload'))];
        yield 'ALPH after VP8L' => [AnimatedImages::riff($vp8x, $anim, AnimatedImages::anmf(4, 4, AnimatedImages::chunk('VP8L', 'data'), AnimatedImages::chunk('ALPH', 'payload')))];
        yield 'ALPH after VP8' => [AnimatedImages::riff($vp8x, $anim, AnimatedImages::anmf(4, 4, AnimatedImages::chunk('VP8 ', 'data'), AnimatedImages::chunk('ALPH', 'payload')))];
        yield 'two ALPH' => [AnimatedImages::riff($vp8x, $anim, AnimatedImages::anmf(4, 4, AnimatedImages::chunk('ALPH', 'a'), AnimatedImages::chunk('ALPH', 'b'), AnimatedImages::chunk('VP8 ', 'data')))];
        yield 'two VP8L in one frame' => [AnimatedImages::riff($vp8x, $anim, AnimatedImages::anmf(4, 4, AnimatedImages::chunk('VP8L', 'data'), AnimatedImages::chunk('VP8L', 'payload')))];
        yield 'VP8 and VP8L in one frame' => [AnimatedImages::riff($vp8x, $anim, AnimatedImages::anmf(4, 4, AnimatedImages::chunk('VP8 ', 'data'), AnimatedImages::chunk('VP8L', 'payload')))];
        yield 'frame wider than the canvas' => [AnimatedImages::riff($vp8x, $anim, AnimatedImages::anmf(5, 4, AnimatedImages::chunk('VP8L', 'data')))];
        yield 'frame taller than the canvas' => [AnimatedImages::riff($vp8x, $anim, AnimatedImages::anmf(4, 5, AnimatedImages::chunk('VP8L', 'data')))];
        yield 'frame offset past the canvas' => [AnimatedImages::riff($vp8x, $anim, AnimatedImages::anmfAt(2, 0, 4, 4, 0x02, AnimatedImages::chunk('VP8L', 'data')))];
        yield 'frame offset down past the canvas' => [AnimatedImages::riff($vp8x, $anim, AnimatedImages::anmfAt(0, 2, 4, 4, 0x02, AnimatedImages::chunk('VP8L', 'data')))];
        yield 'second VP8X' => [AnimatedImages::riff($vp8x, $anim, $vp8x, $frame)];
        yield 'VP8X of 12 bytes' => [AnimatedImages::riff(AnimatedImages::chunk('VP8X', "\x02" . str_repeat("\0", 11)), $anim, $frame)];
        yield 'ANIM of 4 bytes' => [AnimatedImages::riff($vp8x, AnimatedImages::chunk('ANIM', "\0\0\0\0"), $frame)];
        yield 'ANMF of 8 bytes' => [AnimatedImages::riff($vp8x, $anim, AnimatedImages::chunk('ANMF', str_repeat("\0", 8)))];
        yield 'frame above max pixels' => [AnimatedImages::riff(AnimatedImages::vp8x(0x02, 0x1000000, 0x1000000), $anim, AnimatedImages::anmf(0x1000000, 0x1000000, AnimatedImages::chunk('VP8L', 'data')))];
        yield 'frame sub-chunk past the frame' => [AnimatedImages::riff($vp8x, $anim, AnimatedImages::chunk('ANMF', substr(AnimatedImages::anmf(), 8, 16) . 'VP8L' . pack('V', 40) . 'data'))];
        yield 'frame without bitstream' => [AnimatedImages::riff($vp8x, $anim, AnimatedImages::anmf(4, 4, AnimatedImages::chunk('ALPH', 'alpha'), AnimatedImages::chunk('XYZW', 'x')))];
    }

    #[DataProvider('malformed')]
    public function testMalformedInputGivesNull(string $webp): void
    {
        self::assertNull((new WebpMetadataStripper())->strip($webp));
    }

    public function testTheBoundsAreEnforced(): void
    {
        $webp = AnimatedImages::webpWithMetadata(4);

        self::assertNull((new WebpMetadataStripper(maxBytes: strlen($webp) - 1))->strip($webp));
        self::assertNotNull((new WebpMetadataStripper(maxBytes: strlen($webp)))->strip($webp));
        self::assertNull((new WebpMetadataStripper(maxFrames: 3))->strip($webp));
        self::assertNotNull((new WebpMetadataStripper(maxFrames: 4))->strip($webp));
        self::assertNull((new WebpMetadataStripper(maxPixels: 15))->strip($webp));
        self::assertNotNull((new WebpMetadataStripper(maxPixels: 16))->strip($webp));
    }

    public function testItIsStatelessAcrossCallsForWorkerMode(): void
    {
        $class = new ReflectionClass(WebpMetadataStripper::class);
        self::assertTrue($class->isReadOnly());
        self::assertSame([], $class->getStaticProperties());

        $shared = new WebpMetadataStripper();
        $inputs = [AnimatedImages::webpWithMetadata(2), AnimatedImages::riff(AnimatedImages::vp8x(0x02), 'ANIM'), AnimatedImages::webpWithMetadata(3), AnimatedImages::webpWithMetadata(2) . chr(0)];
        foreach ($inputs as $input) {
            self::assertSame((new WebpMetadataStripper())->strip($input), $shared->strip($input));
        }
    }

    /**
     * An independent walk over RIFF chunks from `$offset` to `$end`: [FourCC, data, raw chunk with padding].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function chunks(string $bytes, int $offset, int $end): array
    {
        $chunks = [];
        while ($offset + 8 <= $end) {
            $size     = self::long($bytes, $offset + 4);
            $padded   = $size + ($size & 1);
            $chunks[] = [substr($bytes, $offset, 4), substr($bytes, $offset + 8, $size), substr($bytes, $offset, 8 + $padded)];
            $offset += 8 + $padded;
        }

        return $chunks;
    }

    private static function long(string $bytes, int $offset): int
    {
        $value = unpack('V', $bytes, $offset);
        self::assertIsArray($value);

        return (int) $value[1];
    }
}
