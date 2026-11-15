<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use GdImage;
use InvalidArgumentException;
use Nowo\StorageKitBundle\Entity\StoredFile;

use function function_exists;
use function getimagesizefromstring;
use function imagealphablending;
use function imagecolorallocatealpha;
use function imagecopyresampled;
use function imagecreatefromstring;
use function imagecreatetruecolor;
use function imagefill;
use function imagesavealpha;
use function imagesx;
use function imagesy;
use function imagetypes;
use function imagewebp;
use function in_array;
use function ini_get;
use function intdiv;
use function max;
use function memory_get_usage;
use function min;
use function ob_get_clean;
use function ob_start;
use function ord;
use function str_contains;
use function strlen;
use function substr;

use const IMAGETYPE_JPEG;
use const IMAGETYPE_PNG;
use const IMAGETYPE_WEBP;
use const IMG_WEBP;

/**
 * Square image checks (GD, 1.6) for profile pictures and logos: accepts PNG, JPEG and still WebP by their content —
 * never the name or the declared type, so SVG, GIF, HTML and anything else are refused —, at most `max_bytes`
 * (checked before anything is parsed) and `max_pixels` (read from the header before decoding: no decompression bomb),
 * and only when the decoded image fits in the memory left ({@see ImageProcessor::fitsInMemory()}, with the progressive
 * JPEG budget). A JPEG is turned upright from its EXIF orientation; the image is cropped to its centred square and
 * re-encoded as WebP squares: the original at `size` px, the `preview` at half and the `thumb` at a quarter of it, each
 * made from the previous one. Re-encoding drops EXIF / GPS / XMP and any payload appended to the image (polyglots).
 * Animated WebP is refused. Needs GD with WebP: without it every image is refused with `off`
 * ({@see self::supported()}), never an error.
 *
 * Stateless (REQ-RUNTIME-002): nothing is kept between calls.
 */
final readonly class SquareImageProcessor
{
    /** Default `upload.square.max_bytes`. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Default `upload.square.max_pixels` (the smaller of it and `upload.max_pixels` applies). */
    public const MAX_PIXELS = 16_000_000;

    /** Default `upload.square.size` (side of the stored original, in pixels). */
    public const SIZE = 256;

    /** Smallest and largest `upload.square.size`. */
    public const SIZE_MIN = 64;

    public const SIZE_MAX = 1024;

    /** The type every square is stored as. */
    public const MIME = 'image/webp';

    /** The accepted types (raster, still). */
    public const MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    /** WebP quality. */
    public const QUALITY = 85;

    private const TYPES = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];

    /** Whether GD can encode WebP here. */
    private bool $webp;

    /**
     * @param int $maxBytes `upload.square.max_bytes`
     * @param int $maxPixels the smaller of `upload.square.max_pixels` and `upload.max_pixels`
     * @param int $size `upload.square.size` ({@see self::SIZE_MIN}–{@see self::SIZE_MAX})
     * @param string|null $memoryLimit the `memory_limit` to plan the decoding against (null: the current ini value)
     * @param bool|null $webp whether GD encodes WebP (null: {@see self::webpSupported()})
     *
     * @throws InvalidArgumentException on a size outside the bounds
     */
    public function __construct(
        private int $maxBytes = self::MAX_BYTES,
        private int $maxPixels = self::MAX_PIXELS,
        private int $size = self::SIZE,
        private ?string $memoryLimit = null,
        ?bool $webp = null,
    ) {
        if ($size < self::SIZE_MIN || $size > self::SIZE_MAX) {
            throw new InvalidArgumentException('The square size must be 64-1024 pixels.');
        }
        $this->webp = $webp ?? self::webpSupported();
    }

    /**
     * Whether this PHP build's GD encodes WebP (`imagewebp()` exists and `imagetypes()` has `IMG_WEBP`).
     */
    public static function webpSupported(): bool
    {
        return function_exists('imagewebp') && (imagetypes() & IMG_WEBP) !== 0;
    }

    /**
     * Whether square images can be stored at all (GD with WebP): false = every upload is refused with `off`.
     */
    public function supported(): bool
    {
        return $this->webp;
    }

    /**
     * `upload.square.max_bytes`.
     */
    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * Side of each stored square: `original`, `preview` and `thumb`.
     *
     * @return array{original: int<16, max>, preview: int<16, max>, thumb: int<16, max>}
     */
    public function sides(): array
    {
        return [
            StoredFile::VARIANT_ORIGINAL => max(16, $this->size),
            StoredFile::VARIANT_PREVIEW  => max(16, intdiv($this->size, 2)),
            StoredFile::VARIANT_THUMB    => max(16, intdiv($this->size, 4)),
        ];
    }

    /**
     * Checks an image and turns it into WebP squares.
     *
     * @throws UploadRefused (`off` when GD cannot encode WebP here)
     */
    public function process(string $bytes): ProcessedImage
    {
        if (!$this->webp) {
            throw new UploadRefused(UploadRefused::OFF);
        }
        if ($bytes === '') {
            throw new UploadRefused(UploadRefused::EMPTY);
        }
        if (strlen($bytes) > $this->maxBytes) {
            throw new UploadRefused(UploadRefused::SIZE);
        }
        $mime = ImageProcessor::sniff($bytes);
        if ($mime === null || !in_array($mime, self::MIME_TYPES, true) || ($mime === 'image/webp' && self::animated($bytes))) {
            throw new UploadRefused(UploadRefused::TYPE);
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || (self::TYPES[$info[2]] ?? null) !== $mime || $info[0] < 1 || $info[1] < 1) {
            throw new UploadRefused(UploadRefused::TYPE);
        }
        $pixels = (int) $info[0] * (int) $info[1];
        $extra  = $mime === 'image/jpeg' ? ImageProcessor::PROGRESSIVE_BYTES_PER_PIXEL : 0;
        if ($pixels > $this->maxPixels || !ImageProcessor::fitsInMemory($pixels, $this->memoryLimit ?? ini_get('memory_limit'), memory_get_usage(true), $extra)) {
            throw new UploadRefused(UploadRefused::PIXELS);
        }
        $image = @imagecreatefromstring($bytes);
        if (!$image instanceof GdImage) {
            throw new UploadRefused(UploadRefused::TYPE);
        }
        if ($mime === 'image/jpeg') {
            $image = ImageProcessor::upright($image, ImageProcessor::jpegOrientation($bytes));
        }
        $sides  = $this->sides();
        $square = $this->square($image, $sides[StoredFile::VARIANT_ORIGINAL]);
        unset($image);
        $original = $this->encode($square);
        $variants = [];
        foreach ([StoredFile::VARIANT_PREVIEW, StoredFile::VARIANT_THUMB] as $name) {
            $square          = $this->square($square, $sides[$name]);
            $variants[$name] = $this->encode($square);
        }

        return new ProcessedImage($original, $variants);
    }

    /**
     * Whether a WebP is animated: the VP8X animation flag, or an `ANIM` chunk near the start.
     */
    public static function animated(string $bytes): bool
    {
        return (substr($bytes, 12, 4) === 'VP8X' && strlen($bytes) > 20 && (ord($bytes[20]) & 0x02) !== 0)
            || str_contains(substr($bytes, 0, 64), 'ANIM');
    }

    /**
     * The centred square of the image, resampled to `$side` px (transparency kept).
     *
     * @param int<1, max> $side
     */
    private function square(GdImage $image, int $side): GdImage
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $crop   = min($width, $height);
        /** @var GdImage $square imagecreatetruecolor() only fails on a size below 1 */
        $square = imagecreatetruecolor($side, $side);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagefill($square, 0, 0, (int) imagecolorallocatealpha($square, 0, 0, 0, 127));
        imagecopyresampled($square, $image, 0, 0, intdiv($width - $crop, 2), intdiv($height - $crop, 2), $side, $side, $crop, $crop);

        return $square;
    }

    /**
     * @return array{bytes: string, mime: string, width: int, height: int}
     */
    private function encode(GdImage $image): array
    {
        ob_start();
        imagewebp($image, null, self::QUALITY);

        return ['bytes' => (string) ob_get_clean(), 'mime' => self::MIME, 'width' => imagesx($image), 'height' => imagesy($image)];
    }
}
