<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use GdImage;
use Nowo\StorageKitBundle\Entity\StoredFile;

use function getimagesizefromstring;
use function imagealphablending;
use function imagecopyresampled;
use function imagecreatefromstring;
use function imagecreatetruecolor;
use function imageflip;
use function imagejpeg;
use function imagepng;
use function imagerotate;
use function imagesavealpha;
use function imagesx;
use function imagesy;
use function imagewebp;
use function in_array;
use function ini_get;
use function max;
use function memory_get_usage;
use function ob_get_clean;
use function ob_start;
use function ord;
use function round;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function unpack;

use const IMAGETYPE_GIF;
use const IMAGETYPE_JPEG;
use const IMAGETYPE_PNG;
use const IMAGETYPE_WEBP;
use const IMG_FLIP_HORIZONTAL;
use const IMG_FLIP_VERTICAL;

/**
 * Server-side image checks (GD): accepts PNG, JPEG, GIF and WebP by their content — never the name or the declared
 * type, so SVG, HTML and anything else are refused — at most `max_bytes` and `max_pixels` (read from the header before
 * decoding: no decompression bomb). JPEG, PNG and still WebP are re-encoded, which drops EXIF / GPS / XMP and any
 * trailing payload (a JPEG is turned upright first, from its EXIF orientation); GIF and animated WebP are kept as they
 * are. Larger images get the configured variants (default: a 1600 px preview and a 400 px thumbnail).
 *
 * Memory: GD holds a decoded image as 4 bytes per pixel. Before decoding, the dimensions must fit twice (the image
 * plus a rotated copy or a variant) in what is left of `memory_limit`, or the upload is refused like one above
 * `max_pixels` instead of ending the request in a fatal out-of-memory. A progressive JPEG also makes libjpeg keep
 * every DCT coefficient while it decodes, about {@see self::PROGRESSIVE_BYTES_PER_PIXEL} more bytes per pixel that
 * `memory_limit` does not even see (a 40 MP progressive 4:4:4 JPEG peaked at 407 MB, audit CJ L1): that is budgeted
 * too. Each variant is made from the previous one, so at most two decoded images are alive at once.
 */
final readonly class ImageProcessor
{
    /** Default `upload.max_bytes`. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Default `upload.max_pixels`. */
    public const MAX_PIXELS = 24_000_000;

    /** GD truecolor: 4 bytes per pixel. */
    public const BYTES_PER_PIXEL = 4;

    /** Decoded images alive at the peak. */
    public const DECODED_COPIES = 2;

    /** Extra decoder memory per pixel of a progressive JPEG (libjpeg coefficient buffer, outside memory_limit). */
    public const PROGRESSIVE_BYTES_PER_PIXEL = 6;

    /** Default `upload.variants` (name => longest side in pixels), largest first. */
    public const SIZES = [StoredFile::VARIANT_PREVIEW => 1600, StoredFile::VARIANT_THUMB => 400];

    /** The accepted types (raster only). */
    public const MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    private const TYPES = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_GIF => 'image/gif', IMAGETYPE_WEBP => 'image/webp'];

    /**
     * @param int $maxBytes `upload.max_bytes`
     * @param int $maxPixels `upload.max_pixels` (shared pixel cap)
     * @param array<string, int> $sizes `upload.variants`, largest first
     * @param string|null $memoryLimit the `memory_limit` to plan the decoding against (null: the current ini value)
     */
    public function __construct(
        private int $maxBytes = self::MAX_BYTES,
        private int $maxPixels = self::MAX_PIXELS,
        private array $sizes = self::SIZES,
        private ?string $memoryLimit = null,
    ) {
    }

    /**
     * `upload.max_bytes`.
     */
    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * The sniffed type, or null when the bytes are not one of the four accepted formats.
     */
    public static function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n")                         => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF")                              => 'image/jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP'   => 'image/webp',
            default                                                              => null,
        };
    }

    /**
     * Checks and re-encodes an image.
     *
     * @throws UploadRefused
     */
    public function process(string $bytes): ProcessedImage
    {
        if ($bytes === '') {
            throw new UploadRefused(UploadRefused::EMPTY);
        }
        if (strlen($bytes) > $this->maxBytes) {
            throw new UploadRefused(UploadRefused::SIZE);
        }
        $mime = self::sniff($bytes);
        $info = $mime === null ? false : @getimagesizefromstring($bytes);
        if ($info === false || (self::TYPES[$info[2]] ?? null) !== $mime) {
            throw new UploadRefused(UploadRefused::TYPE);
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width < 1 || $height < 1) {
            throw new UploadRefused(UploadRefused::TYPE);
        }
        if ($width * $height > $this->maxPixels) {
            throw new UploadRefused(UploadRefused::PIXELS);
        }
        if ($mime === 'image/gif' || ($mime === 'image/webp' && str_contains(substr($bytes, 0, 64), 'ANIM'))) {
            return new ProcessedImage(['bytes' => $bytes, 'mime' => $mime, 'width' => $width, 'height' => $height]);
        }
        $extra = $mime === 'image/jpeg' && self::jpegIsProgressive($bytes) ? self::PROGRESSIVE_BYTES_PER_PIXEL : 0;
        if (!self::fitsInMemory($width * $height, $this->memoryLimit ?? ini_get('memory_limit'), memory_get_usage(true), $extra)) {
            throw new UploadRefused(UploadRefused::PIXELS);
        }
        $image = @imagecreatefromstring($bytes);
        if (!$image instanceof GdImage) {
            throw new UploadRefused(UploadRefused::TYPE);
        }
        if ($mime === 'image/jpeg') {
            $image = $this->upright($image, self::jpegOrientation($bytes));
        }
        $original = $this->encode($image, $mime);
        $variants = [];
        // Largest variant first, each from the previous one: the original is freed once the first variant exists.
        foreach ($this->sizes as $name => $max) {
            if (max($original['width'], $original['height']) > $max) {
                $image           = $this->fit($image, $max);
                $variants[$name] = $this->encode($image, $mime);
            }
        }
        unset($image);

        return new ProcessedImage($original, $variants);
    }

    /**
     * Whether `$pixels` decoded {@see self::DECODED_COPIES} times, plus `$extraPerPixel` decoder bytes per pixel (a
     * progressive JPEG), fit in what `$memoryLimit` (an ini value: `512M`, `-1`…) leaves above `$usage` bytes. No limit
     * (`-1`, `0`, empty) always fits.
     */
    public static function fitsInMemory(int $pixels, string $memoryLimit, int $usage, int $extraPerPixel = 0): bool
    {
        $limit = self::iniBytes($memoryLimit);

        return $limit <= 0 || $usage + $pixels * (self::BYTES_PER_PIXEL * self::DECODED_COPIES + $extraPerPixel) <= $limit;
    }

    /**
     * Whether a JPEG is progressive (an SOF2 / SOF6 / SOF10 / SOF14 frame before the scan data).
     */
    public static function jpegIsProgressive(string $bytes): bool
    {
        $offset = 2;
        $length = strlen($bytes);
        while ($offset + 4 <= $length && $bytes[$offset] === "\xFF") {
            $marker = ord($bytes[$offset + 1]);
            if (in_array($marker, [0xC2, 0xC6, 0xCA, 0xCE], true)) {
                return true;
            }
            $size = self::number('n', $bytes, $offset + 2);
            if ($marker === 0xDA || $size < 2) {
                break;
            }
            $offset += 2 + $size;
        }

        return false;
    }

    /**
     * The EXIF orientation (1–8) of a JPEG, read from its APP1 block without the exif extension; 1 when absent.
     */
    public static function jpegOrientation(string $bytes): int
    {
        $offset = 2;
        $length = strlen($bytes);
        while ($offset + 4 <= $length && $bytes[$offset] === "\xFF") {
            $marker = ord($bytes[$offset + 1]);
            $size   = self::number('n', $bytes, $offset + 2);
            if ($marker === 0xE1 && substr($bytes, $offset + 4, 4) === 'Exif') {
                return self::tiffOrientation(substr($bytes, $offset + 10, max(0, $size - 8)));
            }
            if ($marker === 0xDA || $size < 2) {
                break;
            }
            $offset += 2 + $size;
        }

        return 1;
    }

    private static function iniBytes(string $value): int
    {
        $value  = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1024 ** 3,
            'm'     => $number * 1024 ** 2,
            'k'     => $number * 1024,
            default => $number,
        };
    }

    private static function tiffOrientation(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }
        $little = str_starts_with($tiff, 'II');
        $short  = $little ? 'v' : 'n';
        $long   = $little ? 'V' : 'N';
        $ifd    = self::number($long, $tiff, 4);
        if ($ifd + 2 > strlen($tiff)) {
            return 1;
        }
        $entries = self::number($short, $tiff, $ifd);
        for ($i = 0; $i < $entries; ++$i) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > strlen($tiff)) {
                break;
            }
            if (self::number($short, $tiff, $entry) === 0x0112) {
                $value = self::number($short, $tiff, $entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    /** An unsigned 16- or 32-bit number at `$offset` (`n` / `v`: 16 bits, `N` / `V`: 32 bits); 0 past the end. */
    private static function number(string $format, string $bytes, int $offset): int
    {
        $width = in_array($format, ['n', 'v'], true) ? 2 : 4;
        $value = $offset >= 0 && $offset + $width <= strlen($bytes) ? unpack($format, $bytes, $offset) : false;

        return $value === false ? 0 : (int) $value[1];
    }

    private function upright(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        // imagerotate() turns counter-clockwise: 90 = a quarter left, 270 = a quarter right (after the flip above).
        $angle = match ($orientation) {
            3       => 180,
            6, 7    => 270,
            5, 8    => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    private function fit(GdImage $image, int $max): GdImage
    {
        $width   = imagesx($image);
        $height  = imagesy($image);
        $scale   = $max / max($width, $height);
        $resized = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);

        return $resized;
    }

    /**
     * @return array{bytes: string, mime: string, width: int, height: int}
     */
    private function encode(GdImage $image, string $mime): array
    {
        imagesavealpha($image, true);
        ob_start();
        match ($mime) {
            'image/png'  => imagepng($image, null, 6),
            'image/webp' => imagewebp($image, null, 85),
            default      => imagejpeg($image, null, 85),
        };

        return ['bytes' => (string) ob_get_clean(), 'mime' => $mime, 'width' => imagesx($image), 'height' => imagesy($image)];
    }
}
