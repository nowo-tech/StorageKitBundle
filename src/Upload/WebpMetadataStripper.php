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
 * Kept: `VP8X` (its ICC, EXIF and XMP flags and reserved bits cleared), the single `ANIM` and every `ANMF` frame (header
 * reserved bits cleared) with its single `VP8 ` / `VP8L` bitstream and, before a `VP8 ` one, its `ALPH`, copied as
 * they are (never decoded).
 *
 * Dropped: `EXIF`, `XMP `, `ICCP` and every other chunk, inside a frame too, an `ALPH` next to a `VP8L` bitstream
 * (libwebp ignores it), and anything after the RIFF size. The RIFF size is rewritten; odd-sized chunks keep their
 * padding byte.
 *
 * Malformed input gives null and the caller refuses the upload. Following libwebp's demuxer (ParseVP8XChunks,
 * IsValidExtendedFormat), that is: not a RIFF / WEBP file, a RIFF size past the end, a truncated chunk or missing
 * padding, a first chunk that is not an animated `VP8X`, a second `VP8X`, no `ANIM` before the first `ANMF` or a second
 * `ANIM`, no frame, image data (`ALPH`, `VP8 `, `VP8L`) outside a frame, a frame without bitstream, with two bitstreams,
 * two `ALPH` or an `ALPH` after its bitstream, a frame outside the canvas or above `max_pixels`, more than
 * {@see GifMetadataStripper::MAX_FRAMES} frames, or more than `max_bytes`. Stateless (FrankenPHP worker mode: nothing
 * kept between calls).
 *
 * @internal
 */
final readonly class WebpMetadataStripper
{
    /** VP8X flags kept: alpha (0x10) and animation (0x02). ICC (0x20), EXIF (0x08), XMP (0x04) and reserved bits go. */
    private const KEPT_FLAGS = 0x12;

    private const ANIMATION = 0x02;

    /** ANMF flags kept: blending (0x02) and disposal (0x01); the reserved bits go. */
    private const FRAME_FLAGS = 0x03;

    /** Image data, valid only inside an `ANMF` frame of an animation. */
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
        // Anything after the RIFF size is dropped. The checks follow libwebp's demuxer (ParseVP8XChunks,
        // IsValidExtendedFormat): what it would refuse or never read is refused, not stored.
        $out    = '';
        $frames = 0;
        $anim   = false;
        $canvas = [0, 0];
        $offset = 12;
        while ($offset < $end) {
            $chunk = $this->chunk($bytes, $offset, $end);
            if ($chunk === null) {
                return null;
            }
            [$fourcc, $data, $size, $next] = $chunk;
            if ($fourcc === 'VP8X') {
                if ($offset !== 12 || $size !== 10) {
                    return null;
                }
                $canvas = [1 + $this->uint24($bytes, $data + 4), 1 + $this->uint24($bytes, $data + 7)];
                $out .= 'VP8X' . pack('V', 10) . chr(ord($bytes[$data]) & self::KEPT_FLAGS) . "\0\0\0" . substr($bytes, $data + 4, 6);
            } elseif ($fourcc === 'ANIM') {
                // Exactly one, before the first frame.
                if ($anim || $size !== 6) {
                    return null;
                }
                $anim = true;
                $out .= substr($bytes, $offset, $next - $offset);
            } elseif ($fourcc === 'ANMF') {
                $frame = $anim && ++$frames <= $this->maxFrames ? $this->frame($bytes, $data, $size, $canvas) : null;
                if ($frame === null) {
                    return null;
                }
                $out .= $frame;
            } elseif (in_array($fourcc, self::BITSTREAMS, true)) {
                // Image data outside a frame of an animation: never decoded.
                return null;
            }
            // EXIF, XMP, ICCP and unknown chunks: dropped.
            $offset = $next;
        }

        return $frames > 0 ? 'RIFF' . pack('V', 4 + strlen($out)) . 'WEBP' . $out : null;
    }

    /**
     * The `ANMF` chunk rebuilt with its 16-byte header (reserved flag bits cleared), an `ALPH` sub-chunk when it goes
     * with a lossy `VP8 ` one, and its single `VP8 ` / `VP8L` bitstream; null when malformed or outside the canvas.
     *
     * @param array{0: int, 1: int} $canvas the `VP8X` canvas width and height
     */
    private function frame(string $bytes, int $data, int $size, array $canvas): ?string
    {
        if ($size < 16) {
            return null;
        }
        // Header: X / 2, Y / 2, width - 1, height - 1 (24 bits each), duration (24 bits), flags.
        $x      = 2 * $this->uint24($bytes, $data);
        $y      = 2 * $this->uint24($bytes, $data + 3);
        $width  = 1 + $this->uint24($bytes, $data + 6);
        $height = 1 + $this->uint24($bytes, $data + 9);
        if ($width * $height > $this->maxPixels || $x + $width > $canvas[0] || $y + $height > $canvas[1]) {
            return null;
        }
        $end       = $data + $size;
        $offset    = $data + 16;
        $alpha     = '';
        $bitstream = null;
        $lossy     = false;
        while ($offset < $end) {
            $chunk = $this->chunk($bytes, $offset, $end);
            if ($chunk === null) {
                return null;
            }
            [$fourcc, , , $next] = $chunk;
            $raw                 = substr($bytes, $offset, $next - $offset);
            if ($fourcc === 'ALPH') {
                // At most one, before the bitstream.
                if ($alpha !== '' || $bitstream !== null) {
                    return null;
                }
                $alpha = $raw;
            } elseif ($fourcc === 'VP8 ' || $fourcc === 'VP8L') {
                // Exactly one bitstream per frame.
                if ($bitstream !== null) {
                    return null;
                }
                $bitstream = $raw;
                $lossy     = $fourcc === 'VP8 ';
            }
            // Unknown sub-chunks: dropped.
            $offset = $next;
        }
        if ($bitstream === null) {
            return null;
        }
        // A VP8L bitstream carries its own alpha: libwebp ignores an ALPH next to it, so it is dropped.
        $out = substr($bytes, $data, 15) . chr(ord($bytes[$data + 15]) & self::FRAME_FLAGS) . ($lossy ? $alpha : '') . $bitstream;

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

    /** A little-endian 24-bit number (the caller checked the bounds). */
    private function uint24(string $bytes, int $offset): int
    {
        return $this->long(substr($bytes, $offset, 3) . "\0", 0);
    }

    /** A little-endian 32-bit number (the caller checked the bounds). */
    private function long(string $bytes, int $offset): int
    {
        /** @var array{1: int} $value */
        $value = unpack('V', $bytes, $offset);

        return $value[1];
    }
}
