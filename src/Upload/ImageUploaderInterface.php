<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Exception\RetryableException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;

/**
 * Port of the image upload (1.3, public API, SemVer; architecture bar rule 1): aliased to {@see ImageUploader};
 * type-hint this interface in your application layer.
 *
 * The row is written with a DBAL insert: no entity manager is flushed, so an upload never commits a change the
 * application left pending in its own unit of work.
 */
interface ImageUploaderInterface
{
    /**
     * Stores an image for `$user` in a context and returns its view.
     *
     * Outside a transaction the row is committed at once and the `file_uploaded` {@see StorageAuditEvent} follows.
     * Inside a transaction you opened (the host controls the unit of work) the row joins it and nothing is
     * dispatched: call {@see afterCommit()} with the view once your transaction committed (drop it on rollback).
     *
     * @param object $user security user (writes are checked by the context)
     *
     * @throws UploadRefused
     * @throws RetryableException a deadlock or lock wait timeout on the insert (1.7: rethrown, no longer an
     *                            UploadRefused; the objects written are deleted again, so a retry is safe)
     * @throws TransientStorageFailure a runner's transient failure or the owner lock not obtained in time (1.7:
     *                                 rethrown the same way)
     * @throws DBALException a lost savepoint (commit outcome unknown, CONFLICT_002; 1.7: rethrown the same way)
     */
    public function uploadImage(object $user, string $contextType, string $contextId, string $bytes, string $originalName): StoredFileView;

    /**
     * After the host transaction committed: records the `file_uploaded` audit event of each upload.
     */
    public function afterCommit(StoredFileView ...$uploaded): void;
}
