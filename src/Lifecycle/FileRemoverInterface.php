<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Lifecycle;

/**
 * Removal port (1.6, public API, SemVer): drops one stored file of a known context without waiting for the purge — a
 * replaced or removed profile picture, a deleted logo. Aliased to {@see FileRemover}; type-hint this interface.
 *
 * The file is matched by its public id AND its context, byte for byte (REQ-SEC-006): a UUID learned elsewhere cannot
 * remove another context's file. Both methods write with DBAL statements and never flush an entity manager.
 */
interface FileRemoverInterface
{
    /**
     * Deletes the file's objects from its bucket, then its references and its row. When the bucket refuses (or the
     * profile is not usable any more) the references are dropped and the file is left to the next purge run instead.
     * Bucket calls: call it after your own transaction committed, not inside it ({@see self::expire()} is for that).
     *
     * @return bool false when there is no such file in that context
     */
    public function remove(string $uuid, string $contextType, string $contextId): bool;

    /**
     * Drops the file's references and marks it as an orphan whose retention is over, so the next purge run deletes its
     * objects and row (no bucket call: safe inside a transaction, e.g. a GDPR erasure).
     *
     * @return bool false when there is no such file in that context
     */
    public function expire(string $uuid, string $contextType, string $contextId): bool;
}
