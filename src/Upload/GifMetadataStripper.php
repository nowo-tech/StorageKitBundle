<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use function chr;
use function in_array;
use function ord;
use function strlen;
use function substr;
use function unpack;

/**
 * Removes the metadata of a GIF (2.2.1) without decoding it: one linear, non-recursive pass over the blocks that
 * rebuilds the file from what an animation needs and nothing else.
 *
 * Kept: the header, the logical screen descriptor and the global colour table, every image (descriptor, local colour
 * table, LZW minimum code size and data sub-blocks, copied as they are: the LZW data is never decoded nor re-encoded),
 * the graphic control extensions (rewritten canonically: reserved bits and an unused transparent index cleared) and
 * the loop of a `NETSCAPE2.0` / `ANIMEXTS1.0` application extension (rewritten to its single loop sub-block).
 *
 * Dropped: comment (`0xFE`) and plain text (`0x01`) extensions, every other application extension (XMP, ICC, …),
 * extensions with an unknown label, and anything after the trailer (`0x3B`).
 *
 * Malformed input — truncated, a block running past the end, an unknown block introducer, no image, a frame of no
 * pixels or above `max_pixels`, more than {@see self::MAX_FRAMES} images, or more than `max_bytes` — gives null: the
 * caller refuses the upload. Stateless (FrankenPHP worker mode: nothing kept between calls).
 *
 * @internal
 */
final readonly class GifMetadataStripper
{
    /** At most this many images (frames) in one GIF. */
    public const MAX_FRAMES = 10_000;

    private const TRAILER = 0x3B;

    private const IMAGE = 0x2C;

    private const EXTENSION = 0x21;

    private const GRAPHIC_CONTROL = 0xF9;

    private const APPLICATION = 0xFF;

    private const LOOP_IDENTIFIERS = ['NETSCAPE2.0', 'ANIMEXTS1.0'];

    /**
     * @param int $maxBytes the largest GIF accepted (`upload.max_bytes`)
     * @param int $maxPixels the largest frame accepted (`upload.max_pixels`)
     * @param int $maxFrames the most images (frames) accepted
     */
    public function __construct(
        private int $maxBytes = ImageProcessor::MAX_BYTES,
        private int $maxPixels = ImageProcessor::MAX_PIXELS,
        private int $maxFrames = self::MAX_FRAMES,
    ) {
    }

    /**
     * The GIF without its metadata, or null when it is malformed or out of bounds.
     */
    public function strip(string $bytes): ?string
    {
        $length = strlen($bytes);
        $header = substr($bytes, 0, 6);
        if ($length > $this->maxBytes || $length < 14 || ($header !== 'GIF87a' && $header !== 'GIF89a')) {
            return null;
        }
        $offset = $this->colourTable($length, 13, ord($bytes[10]));
        if ($offset === null) {
            return null;
        }
        $out    = substr($bytes, 0, $offset);
        $frames = 0;
        while ($offset < $length) {
            $introducer = ord($bytes[$offset]);
            if ($introducer === self::TRAILER) {
                // Whatever follows the trailer is dropped.
                return $frames > 0 ? $out . chr(self::TRAILER) : null;
            }
            if ($introducer === self::IMAGE) {
                if (++$frames > $this->maxFrames) {
                    return null;
                }
                $end = $this->image($bytes, $length, $offset);
                if ($end === null) {
                    return null;
                }
                $out .= substr($bytes, $offset, $end - $offset);
                $offset = $end;

                continue;
            }
            if ($introducer !== self::EXTENSION || $offset + 2 > $length) {
                return null;
            }
            $end = $this->subBlocksEnd($bytes, $length, $offset + 2);
            if ($end === null) {
                return null;
            }
            $kept = $this->extension($bytes, $offset, $end);
            if ($kept === null) {
                return null;
            }
            $out .= $kept;
            $offset = $end;
        }

        // No trailer.
        return null;
    }

    /**
     * The offset after an image (descriptor, local table, LZW minimum code size, data sub-blocks), or null.
     */
    private function image(string $bytes, int $length, int $offset): ?int
    {
        if ($offset + 10 > $length) {
            return null;
        }
        $width  = $this->short($bytes, $offset + 5);
        $height = $this->short($bytes, $offset + 7);
        if ($width < 1 || $height < 1 || $width * $height > $this->maxPixels) {
            return null;
        }
        $offset = $this->colourTable($length, $offset + 10, ord($bytes[$offset + 9]));
        if ($offset === null || $offset >= $length) {
            return null;
        }
        $codeSize = ord($bytes[$offset]);
        if ($codeSize < 2 || $codeSize > 8) {
            return null;
        }

        return $this->subBlocksEnd($bytes, $length, $offset + 1);
    }

    /**
     * The kept form of the extension between `$offset` (its introducer) and `$end` (after its terminator): a canonical
     * graphic control or loop extension, an empty string when it is dropped, null when it is malformed.
     */
    private function extension(string $bytes, int $offset, int $end): ?string
    {
        $label = ord($bytes[$offset + 1]);
        $data  = $offset + 2;
        if ($label === self::GRAPHIC_CONTROL) {
            // Exactly one 4-byte sub-block, then the terminator.
            if ($end - $data !== 6 || ord($bytes[$data]) !== 4) {
                return null;
            }
            $packed      = ord($bytes[$data + 1]) & 0x1F;
            $transparent = ($packed & 0x01) !== 0 ? $bytes[$data + 4] : "\0";

            return "\x21\xF9\x04" . chr($packed) . substr($bytes, $data + 2, 2) . $transparent . "\0";
        }
        if ($label !== self::APPLICATION) {
            // Comment, plain text, unknown labels: dropped.
            return '';
        }
        if (ord($bytes[$data]) !== 11) {
            return null;
        }
        $identifier = substr($bytes, $data + 1, 11);
        $loop       = $data + 12;
        // The loop sub-block: size 3, id 1, a 16-bit count. Any other sub-block (buffering, payloads) is dropped.
        if (!in_array($identifier, self::LOOP_IDENTIFIERS, true) || ord($bytes[$loop]) !== 3 || ord($bytes[$loop + 1]) !== 1) {
            return '';
        }

        return "\x21\xFF\x0B" . $identifier . "\x03\x01" . substr($bytes, $loop + 2, 2) . "\0";
    }

    /**
     * The offset after the colour table announced by `$packed` (a logical screen or image descriptor flag byte) that
     * starts at `$offset`, or null when it runs past the end.
     */
    private function colourTable(int $length, int $offset, int $packed): ?int
    {
        if (($packed & 0x80) !== 0) {
            $offset += 3 << (($packed & 0x07) + 1);
        }

        return $offset <= $length ? $offset : null;
    }

    /**
     * The offset after a chain of data sub-blocks (size byte, data) ended by a zero-size block, or null when it runs
     * past the end.
     */
    private function subBlocksEnd(string $bytes, int $length, int $offset): ?int
    {
        while ($offset < $length) {
            $size = ord($bytes[$offset]);
            ++$offset;
            if ($size === 0) {
                return $offset;
            }
            $offset += $size;
        }

        return null;
    }

    /** A little-endian 16-bit number (the caller checked the bounds). */
    private function short(string $bytes, int $offset): int
    {
        /** @var array{1: int} $value */
        $value = unpack('v', $bytes, $offset);

        return $value[1];
    }
}
