<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Ui;

use function number_format;

/**
 * Human-readable byte counts for the usage lines (binary units, one decimal).
 */
final class Bytes
{
    private const UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];

    /**
     * `1536` → `1.5 KiB`.
     */
    public static function format(int $bytes): string
    {
        $value = (float) $bytes;
        $unit  = 0;
        while ($value >= 1024 && $unit < 4) {
            $value /= 1024;
            ++$unit;
        }

        return $unit === 0 ? $bytes . ' B' : number_format($value, 1, '.', '') . ' ' . self::UNITS[$unit];
    }
}
