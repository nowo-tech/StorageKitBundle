<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

use function max;
use function min;
use function preg_match;
use function str_contains;
use function strlen;

/**
 * One byte range of a `Range` request (2.2, RFC 9110 §14): first and last byte, both included. Only a single range is
 * served: a list of ranges, another unit, a malformed or over-long value is ignored (the whole file is sent with
 * `200`), as RFC 9110 allows. A range that starts past the end is unsatisfiable (`416`).
 */
final readonly class ByteRange
{
    /** Longest number accepted in a range (18 digits: no integer overflow). */
    private const NUMBER = '\d{0,18}';

    /**
     * @param int $start first byte (0-based)
     * @param int $end last byte, included
     */
    private function __construct(
        public int $start,
        public int $end,
    ) {
    }

    /**
     * The range `$header` asks for in an object of `$size` bytes: null = no usable range (send the whole object),
     * false = unsatisfiable (`416`).
     */
    public static function parse(?string $header, int $size): self|false|null
    {
        if ($header === null || $size <= 0 || strlen($header) > 64 || str_contains($header, ',')) {
            return null;
        }
        if (preg_match('/^bytes=(' . self::NUMBER . ')-(' . self::NUMBER . ')$/D', $header, $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            return null;
        }
        if ($m[1] === '') {
            // A suffix: the last n bytes.
            $suffix = (int) $m[2];

            return $suffix === 0 ? false : new self(max(0, $size - $suffix), $size - 1);
        }
        $start = (int) $m[1];
        if ($m[2] !== '' && (int) $m[2] < $start) {
            return null;
        }
        if ($start >= $size) {
            return false;
        }

        return new self($start, $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1));
    }

    /**
     * Bytes in the range.
     */
    public function length(): int
    {
        return $this->end - $this->start + 1;
    }

    /**
     * The `Content-Range` value of a `206` for an object of `$size` bytes.
     */
    public function contentRange(int $size): string
    {
        return 'bytes ' . $this->start . '-' . $this->end . '/' . $size;
    }
}
