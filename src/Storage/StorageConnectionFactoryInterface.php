<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Storage;

/**
 * Opens a connection to one bucket. Production uses {@see S3StorageConnectionFactory} (S3 API only: there is no local
 * disk adapter); tests swap in an in-memory one (`nowo_storage_kit.connection_factory`).
 */
interface StorageConnectionFactoryInterface
{
    /**
     * A connection to the bucket of `$settings`.
     */
    public function connect(S3Settings $settings): StorageConnection;
}
