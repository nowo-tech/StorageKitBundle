<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use League\Flysystem\Config;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;

use function strlen;
use function substr;

/**
 * The adapter of one bucket under a prefix, with the simulated failures.
 */
final class PrefixedMemoryAdapter extends InMemoryFilesystemAdapter
{
    public function __construct(
        private readonly InMemoryFilesystemAdapter $inner,
        private readonly string $prefix,
        private readonly InMemoryBuckets $buckets,
    ) {
        parent::__construct();
    }

    public function write(string $path, string $contents, Config $config): void
    {
        if ($this->buckets->writeFails()) {
            throw UnableToWriteFile::atLocation($path, 'bucket unavailable');
        }
        $this->inner->write($this->path($path), $this->buckets->corruptWrites ? $contents . '!' : $contents, $config);
    }

    public function read(string $path): string
    {
        if ($this->buckets->failReads) {
            throw UnableToReadFile::fromLocation($path, 'bucket unavailable');
        }

        return $this->inner->read($this->path($path));
    }

    public function fileExists(string $path): bool
    {
        return $this->inner->fileExists($this->path($path));
    }

    public function delete(string $path): void
    {
        if ($this->buckets->failDeletes) {
            throw UnableToDeleteFile::atLocation($path, 'bucket unavailable');
        }
        $this->inner->delete($this->path($path));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $strip = $this->prefix === '' ? 0 : strlen($this->prefix) + 1;
        foreach ($this->inner->listContents($this->prefix === '' ? $path : $this->path($path), $deep) as $item) {
            yield $item->withPath(substr($item->path(), $strip));
        }
    }

    private function path(string $path): string
    {
        return $this->prefix === '' ? $path : $this->prefix . '/' . $path;
    }
}
