<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use function str_repeat;
use function strlen;
use function substr;

/**
 * Stream wrapper `growing://<n>` of the tests (2.2): a regular file whose `fstat()` says `<n>` bytes but whose reads
 * return twice as many, as a source that grows between the size check and the transfer.
 */
final class GrowingFileStream
{
    public const SCHEME = 'growing';

    /** @var resource|null */
    public $context;

    private string $data = '';

    private int $position = 0;

    private int $size = 0;

    public function stream_open(string $path): bool
    {
        $this->size = (int) substr($path, strlen(self::SCHEME) + 3);
        $this->data = str_repeat('x', $this->size * 2);

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->data, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    public function stream_seek(int $offset): bool
    {
        $this->position = $offset;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['mode' => 0100644, 'size' => $this->size];
    }

    /** @return array<string, int> */
    public function url_stat(string $path): array
    {
        return ['mode' => 0100644, 'size' => (int) substr($path, strlen(self::SCHEME) + 3)];
    }
}
