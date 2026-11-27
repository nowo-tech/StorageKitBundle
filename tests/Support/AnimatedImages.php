<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use function chr;
use function implode;
use function intdiv;
use function ord;
use function pack;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Synthetic animated GIF and WebP fixtures (2.2.1 metadata stripping): built byte by byte, no real photo. Every planted
 * metadata payload contains {@see self::SECRET}, so a test only has to check that the marker is gone.
 */
final class AnimatedImages
{
    /** Marker inside every metadata payload. */
    public const SECRET = 'SECRET-METADATA';

    /**
     * One GIF image block (descriptor, local table, LZW data) made by GD: a `$width` × `$height` frame.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function gifImageBlock(int $width = 4, int $height = 4): string
    {
        $gif    = Images::gif($width, $height);
        $packed = ord($gif[10]);
        $start  = 13 + (($packed & 0x80) !== 0 ? 3 << (($packed & 0x07) + 1) : 0);

        // GD writes the header, the screen descriptor, the global table, one image and the trailer.
        return substr($gif, $start, -1);
    }

    /** A GIF89a header with a 4 × 4 logical screen and a 2-colour global table. */
    public static function gifHead(int $width = 4, int $height = 4): string
    {
        return 'GIF89a' . pack('vv', $width, $height) . "\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF";
    }

    /** A graphic control extension (`$packed`: disposal, user input, transparency flag), delay, transparent index. */
    public static function gce(int $packed = 0x04, int $delay = 10, int $transparent = 0): string
    {
        return "\x21\xF9\x04" . chr($packed) . pack('v', $delay) . chr($transparent) . "\x00";
    }

    /** A `NETSCAPE2.0` (or `ANIMEXTS1.0`) loop extension, with optional extra sub-blocks after the loop one. */
    public static function loop(int $count = 0, string $identifier = 'NETSCAPE2.0', string $extra = ''): string
    {
        return "\x21\xFF\x0B" . $identifier . "\x03\x01" . pack('v', $count) . $extra . "\x00";
    }

    /** An extension `$label` whose payload is split into sub-blocks of at most 255 bytes. */
    public static function extension(int $label, string $payload): string
    {
        return "\x21" . chr($label) . self::subBlocks($payload);
    }

    /** An application extension `$identifier` (11 bytes) carrying `$payload`. */
    public static function application(string $identifier, string $payload): string
    {
        return "\x21\xFF\x0B" . $identifier . self::subBlocks($payload);
    }

    /** `$payload` as data sub-blocks of at most 255 bytes, then the terminator. */
    public static function subBlocks(string $payload): string
    {
        $blocks = '';
        for ($i = 0; $i < strlen($payload); $i += 255) {
            $part = substr($payload, $i, 255);
            $blocks .= chr(strlen($part)) . $part;
        }

        return $blocks . "\x00";
    }

    /**
     * An animated GIF with every kind of metadata planted around `$frames` frames, plus bytes after the trailer.
     *
     * @param int<1, max> $frames
     */
    public static function gifWithMetadata(int $frames = 3): string
    {
        $secret = self::SECRET;
        $gif    = self::gifHead()
            . self::extension(0xFE, "comment {$secret}")
            // A second loop sub-block (id 2, "buffering") carrying a payload: dropped, only the loop count stays.
            . self::loop(0, 'NETSCAPE2.0', chr(1 + strlen($secret)) . "\x02" . $secret)
            . self::application('XMP DataXMP', "<x:xmpmeta>{$secret}</x:xmpmeta>" . str_repeat('x', 600))
            . self::application('ICCRGBG1012', "icc {$secret}");
        for ($i = 0; $i < $frames; ++$i) {
            $gif .= self::gce(0x04, 10 + $i) . self::gifImageBlock()
                . self::extension(0x01, str_repeat("\0", 12) . "text {$secret}");
        }

        return $gif . "\x3Btrailing {$secret}\x3B";
    }

    /** A RIFF chunk with its padding byte when `$data` has an odd length. */
    public static function chunk(string $fourcc, string $data): string
    {
        return $fourcc . pack('V', strlen($data)) . $data . (strlen($data) % 2 === 1 ? "\0" : '');
    }

    /** A RIFF / WEBP file made of `$chunks`, with the right RIFF size. */
    public static function riff(string ...$chunks): string
    {
        $body = 'WEBP' . implode('', $chunks);

        return 'RIFF' . pack('V', strlen($body)) . $body;
    }

    /** A `VP8X` chunk: `$flags`, 3 reserved bytes, the canvas size. */
    public static function vp8x(int $flags = 0x12, int $width = 4, int $height = 4, string $reserved = "\0\0\0"): string
    {
        return self::chunk('VP8X', chr($flags) . $reserved . substr(pack('V', $width - 1), 0, 3) . substr(pack('V', $height - 1), 0, 3));
    }

    /** An `ANMF` frame at the canvas origin: its 16-byte header (size, duration, flags) then `$chunks`. */
    public static function anmf(int $width = 4, int $height = 4, string ...$chunks): string
    {
        return self::anmfAt(0, 0, $width, $height, 0x02, ...$chunks);
    }

    /** An `ANMF` frame at (`$x`, `$y`) (even numbers), with its `$flags` byte, then `$chunks`. */
    public static function anmfAt(int $x, int $y, int $width, int $height, int $flags, string ...$chunks): string
    {
        $header = substr(pack('V', intdiv($x, 2)), 0, 3) . substr(pack('V', intdiv($y, 2)), 0, 3)
            . substr(pack('V', $width - 1), 0, 3) . substr(pack('V', $height - 1), 0, 3) . "\x64\0\0" . chr($flags);

        return self::chunk('ANMF', $header . implode('', $chunks));
    }

    /**
     * An animated WebP with EXIF, XMP, ICC and unknown chunks (inside frames too) and bytes after the RIFF size.
     *
     * @param int<1, max> $frames
     */
    public static function webpWithMetadata(int $frames = 3): string
    {
        $secret = self::SECRET;
        $chunks = [self::vp8x(0x02 | 0x10 | 0x20 | 0x08 | 0x04 | 0x01 | 0xC0, 4, 4, "\x01\x02\x03"), self::chunk('ICCP', "icc {$secret}"), self::chunk('ANIM', "\0\0\0\0\0\0")];
        for ($i = 0; $i < $frames; ++$i) {
            $chunks[] = self::anmf(4, 4, self::chunk('ALPH', "alpha{$i}"), self::chunk('VP8 ', "bitstream-{$i}x"), self::chunk('XYZW', "in-frame {$secret}"));
        }
        $chunks[] = self::chunk('EXIF', "exif {$secret}");
        $chunks[] = self::chunk('XMP ', "<x:xmpmeta>{$secret}</x:xmpmeta>");
        $chunks[] = self::chunk('ABCD', "unknown {$secret}");

        return self::riff(...$chunks) . "trailing {$secret}";
    }
}
