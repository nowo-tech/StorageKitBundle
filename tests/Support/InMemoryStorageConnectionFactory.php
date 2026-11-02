<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use League\Flysystem\Filesystem;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\StorageKitBundle\Storage\StorageConnection;
use Nowo\StorageKitBundle\Storage\StorageConnectionFactoryInterface;

/**
 * Storage double: buckets in memory ({@see InMemoryBuckets}), pre-signed URLs
 * `https://<bucket>.storage.test/<key>?type=…&disposition=…&expires=…`.
 */
final readonly class InMemoryStorageConnectionFactory implements StorageConnectionFactoryInterface
{
    public function __construct(
        private InMemoryBuckets $buckets,
    ) {
    }

    public function connect(S3Settings $settings): StorageConnection
    {
        $this->buckets->connected[] = $settings->bucket;
        $adapter                    = $this->buckets->adapter(($settings->endpoint ?? '') . '|' . $settings->bucket);
        $prefixed                   = new PrefixedMemoryAdapter($adapter, $settings->prefix, $this->buckets);

        return new StorageConnection(new Filesystem($prefixed), new FakePresigner($settings), $settings);
    }
}
