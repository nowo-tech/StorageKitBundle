<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use GdImage;

use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefill;
use function imagegif;
use function imageinterlace;
use function imagejpeg;
use function imagepng;
use function imagewebp;
use function ob_get_clean;
use function ob_start;

/**
 * Test images made with GD.
 */
final class Images
{
    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function png(int $width = 20, int $height = 10): string
    {
        return self::encode($width, $height, static fn ($image): bool => imagepng($image));
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function jpeg(int $width = 20, int $height = 10, bool $progressive = false): string
    {
        return self::encode($width, $height, static function ($image) use ($progressive): bool {
            imageinterlace($image, $progressive);

            return imagejpeg($image);
        });
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function gif(int $width = 20, int $height = 10): string
    {
        return self::encode($width, $height, static fn ($image): bool => imagegif($image));
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    public static function webp(int $width = 20, int $height = 10): string
    {
        return self::encode($width, $height, static fn ($image): bool => imagewebp($image));
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     * @param callable(GdImage): bool $writer
     */
    private static function encode(int $width, int $height, callable $writer): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 60));
        ob_start();
        $writer($image);

        return (string) ob_get_clean();
    }
}
