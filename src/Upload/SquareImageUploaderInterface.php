<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Exception\RetryableException;
use Nowo\TransactionContract\TransientStorageFailure;

/**
 * Port of the square image upload (1.6, public API, SemVer): profile pictures, logos and any other image shown as a
 * square. Aliased to {@see ImageUploader}; type-hint this interface.
 *
 * Same flow and guarantees as {@see ImageUploaderInterface::uploadImage()} (the context decides who may write, the
 * scope's bucket or the instance one, the optional limit, random server-side keys, DBAL insert, no entity manager
 * flushed, audit after commit through {@see ImageUploaderInterface::afterCommit()}), but the checks are
 * {@see SquareImageProcessor}'s: PNG, JPEG or still WebP by content, `upload.square.max_bytes`, `upload.max_pixels`
 * before decoding, centred square re-encoded as WebP with `preview` and `thumb` squares.
 */
interface SquareImageUploaderInterface
{
    /**
     * Stores a square image for `$user` in a context and returns its view (an orphan until something references it).
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
    public function uploadSquareImage(object $user, string $contextType, string $contextId, string $bytes, string $originalName): StoredFileView;
}
