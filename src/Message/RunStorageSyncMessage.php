<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Message;

/**
 * Run (a time slice of) a storage move sync on a Messenger worker. Route it to a maintenance transport in the host.
 */
final readonly class RunStorageSyncMessage
{
    /**
     * Dependencies and configuration.
     */
    public function __construct(
        public int $syncId,
    ) {
    }
}
