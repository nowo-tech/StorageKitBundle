<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Lifecycle;

/**
 * What {@see FileRemoverInterface} removed or expired (1.6). Called inside your own transaction, the audit event waits:
 * pass it to {@see FileRemoverInterface::afterCommit()} once you committed (outside a transaction it is recorded at once).
 */
final readonly class RemovedFile
{
    /**
     * @param string $uuid public id of the file
     * @param string|null $scope scope of the file's profile (null = the instance)
     * @param bool $expired true when the file was left to the next purge (expired, or its bucket refused the delete)
     */
    public function __construct(
        public string $uuid,
        public string $contextType,
        public string $contextId,
        public ?string $scope,
        public bool $expired,
    ) {
    }
}
