<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

/**
 * Buckets of the in-memory storage double (one per endpoint + bucket) and the failures it simulates.
 */
final class InMemoryBuckets
{
    /** @var array<string, InMemoryFilesystemAdapter> */
    public array $buckets = [];

    public bool $failWrites = false;

    public ?int $writesLeft = null;

    public bool $failDeletes = false;

    public bool $failReads = false;

    public bool $corruptWrites = false;

    /** @var list<string> */
    public array $connected = [];

    public function adapter(string $key): InMemoryFilesystemAdapter
    {
        return $this->buckets[$key] ??= new InMemoryFilesystemAdapter();
    }

    /**
     * Objects of a bucket, full key => contents.
     *
     * @return array<string, string>
     */
    public function objects(string $bucket, ?string $endpoint = null): array
    {
        $adapter = $this->buckets[($endpoint ?? '') . '|' . $bucket] ?? null;
        $objects = [];
        if ($adapter === null) {
            return [];
        }
        foreach ($adapter->listContents('', true) as $item) {
            if ($item->isFile()) {
                $objects[$item->path()] = $adapter->read($item->path());
            }
        }

        return $objects;
    }

    public function writeFails(): bool
    {
        if ($this->failWrites || $this->writesLeft === 0) {
            return true;
        }
        if ($this->writesLeft !== null) {
            --$this->writesLeft;
        }

        return false;
    }
}
