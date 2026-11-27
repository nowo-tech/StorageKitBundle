<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use function chr;
use function in_array;
use function ord;
use function pack;
use function strlen;
use function substr;
use function unpack;

/**
 * Removes the metadata of an animated WebP (2.2.1) without decoding it: one linear, non-recursive pass over the RIFF
 * chunks (and the chunks inside each `ANMF` frame) that rebuilds the file from what the animation needs.
 *
 * Kept: `VP8X` (its ICC, EXIF and XMP flags and reserved bits cleared), `ANIM`, `ANMF` (with only its `ALPH`, `VP8 `
 * and `VP8L` sub-chunks) and the `ALPH`, `VP8 `, `VP8L` bitstream chunks, copied as they are (never decoded).
 *
 * Dropped: `EXIF`, `XMP `, `ICCP` and every other chunk, inside a frame too, and anything after the RIFF size. The RIFF
 * size is rewritten; odd-sized chunks keep their padding byte.
 *
 * Malformed input — not a RIFF / WEBP file, a RIFF size past the end, a truncated chunk or missing padding, a first
 * chunk that is not an animated `VP8X`, a bad `ANIM` / `ANMF` size, a frame without bitstream or above `max_pixels`,
 * more than {@see GifMetadataStripper::MAX_FRAMES} frames, or more than `max_bytes` — gives null: the caller refuses
 * the upload. Stateless (FrankenPHP worker mode: nothing kept between calls).
 *
 * @internal
 */
final readonly class WebpMetadataStripper
{
    /** VP8X flags kept: alpha (0x10) and animation (0x02). ICC (0x20), EXIF (0x08), XMP (0x04) and reserved bits go. */
    private const KEPT_FLAGS = 0x12;

    private const ANIMATION = 0x02;

    private const BITSTREAMS = ['ALPH', 'VP8 ', 'VP8L'];

    /**
     * @param int $maxBytes the largest WebP accepted (`upload.max_bytes`)
     * @param int $maxPixels the largest frame accepted (`upload.max_pixels`)
     * @param int $maxFrames the most `ANMF` frames accepted
     */
    public function __construct(
        private int $maxBytes = ImageProcessor::MAX_BYTES,
        private int $maxPixels = ImageProcessor::MAX_PIXELS,
        private int $maxFrames = GifMetadataStripper::MAX_FRAMES,
    ) {
    }

    /**
     * Whether `$bytes` is a WebP whose first chunk is a `VP8X` with the animation flag.
     */
    public static function animated(string $bytes): bool
    {
        return substr($bytes, 12, 4) === 'VP8X' && strlen($bytes) > 20 && (ord($bytes[20]) & self::ANIMATION) !== 0;
    }

    /**
     * The animated WebP without its metadata, or null when it is malformed or out of bounds.
     */
    public function strip(string $bytes): ?string
    {
        $length = strlen($bytes);
        if ($length > $this->maxBytes || $length < 30 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP' || !self::animated($bytes)) {
            return null;
        }
        $end = 8 + $this->long($bytes, 4);
        if ($end < 30 || $end > $length) {
            return null;
        }
        // Anything after the RIFF size is dropped.
        $out    = '';
        $frames = 0;
        $offset = 12;
        while ($offset < $end) {
            $chunk = $this->chunk($bytes, $offset, $end);
            if ($chunk === null) {
                return null;
            }
            [$fourcc, $data, $size, $next] = $chunk;
            $kept                          = match ($fourcc) {
                'VP8X'                 => $offset === 12 && $size === 10 ? 'VP8X' . pack('V', 10) . chr(ord($bytes[$data]) & self::KEPT_FLAGS) . "\0\0\0" . substr($bytes, $data + 4, 6) : null,
                'ANIM'                 => $size === 6 ? substr($bytes, $offset, $next - $offset) : null,
                'ANMF'                 => ++$frames > $this->maxFrames ? null : $this->frame($bytes, $data, $size),
                'ALPH', 'VP8 ', 'VP8L' => substr($bytes, $offset, $next - $offset),
                default                => '',
            };
            if ($kept === null) {
                return null;
            }
            $out .= $kept;
            $offset = $next;
        }

        return 'RIFF' . pack('V', 4 + strlen($out)) . 'WEBP' . $out;
    }

    /**
     * The `ANMF` chunk rebuilt with its 16-byte header and only its bitstream sub-chunks, or null when malformed.
     */
    private function frame(string $bytes, int $data, int $size): ?string
    {
        if ($size < 16) {
            return null;
        }
        // 24-bit width and height minus one, at bytes 6 and 9 of the frame header.
        $width  = 1 + ($this->long($bytes, $data + 6) & 0xFFFFFF);
        $height = 1 + ($this->long($bytes, $data + 9) & 0xFFFFFF);
        if ($width * $height > $this->maxPixels) {
            return null;
        }
        $end       = $data + $size;
        $offset    = $data + 16;
        $out       = substr($bytes, $data, 16);
        $bitstream = false;
        while ($offset < $end) {
            $chunk = $this->chunk($bytes, $offset, $end);
            if ($chunk === null) {
                return null;
            }
            [$fourcc, , , $next] = $chunk;
            if (in_array($fourcc, self::BITSTREAMS, true)) {
                $out .= substr($bytes, $offset, $next - $offset);
                $bitstream = $bitstream || $fourcc !== 'ALPH';
            }
            $offset = $next;
        }
        if (!$bitstream) {
            return null;
        }

        return 'ANMF' . pack('V', strlen($out)) . $out;
    }

    /**
     * The chunk at `$offset`: its FourCC, data offset, data size and the offset after its padding, or null when it
     * (padding included) does not fit before `$end`.
     *
     * @return array{0: string, 1: int, 2: int, 3: int}|null
     */
    private function chunk(string $bytes, int $offset, int $end): ?array
    {
        if ($offset + 8 > $end) {
            return null;
        }
        $size = $this->long($bytes, $offset + 4);
        $next = $offset + 8 + $size + ($size & 1);

        return $next <= $end ? [substr($bytes, $offset, 4), $offset + 8, $size, $next] : null;
    }

    /** A little-endian 32-bit number (the caller checked the bounds). */
    private function long(string $bytes, int $offset): int
    {
        /** @var array{1: int} $value */
        $value = unpack('V', $bytes, $offset);

        return $value[1];
    }
}
