<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Exception\RetryableException;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Context\GenericFileContextInterface;
use Nowo\StorageKitBundle\Reference\FileReferencesInterface;
use Nowo\TransactionContract\TransientStorageFailure;

/**
 * Port of the generic file upload (2.2, public API, SemVer; REQ-ARCH-001 rules 1 and 3): aliased to
 * {@see FileUploader}; type-hint this interface in your application layer.
 *
 * The bytes are stored **as they are** (no processing): the caller declares a media type it has already sniffed
 * (ext-fileinfo magic and its own extension rules); StorageKit checks it exactly against the context's allow-list
 * ({@see GenericFileContextInterface::acceptedTypes()}) and the size against the smallest of the context's limit, the
 * bundle's ceiling (`upload.files.max_bytes`) and the owner's quota ({@see UploadLimitInterface}). A declared raster
 * image type (PNG, JPEG, GIF, WebP) must also match the file's magic bytes.
 *
 * The bytes are streamed to the bucket **before** the row is written, outside any lock StorageKit takes (REQ-RUNTIME-003
 * rule 9): call it outside your own transaction too, so no row lock of yours is held during the transfer. The row is a
 * DBAL insert (a savepoint inside an open transaction; no entity manager is flushed); a failed insert deletes the object
 * again. Like an image, the new file is an orphan until a text or a reference points at it
 * ({@see FileReferencesInterface::sync()}); the purge removes orphans after `purge.retention_days`.
 */
interface FileUploaderInterface
{
    /**
     * Stores a generic file for `$user` in a context and returns its view (`width` and `height` are 0: the bytes were
     * stored as uploaded). Outside a transaction the `file_uploaded` {@see StorageAuditEvent} follows at once; inside
     * yours call {@see self::afterCommit()} once you committed.
     *
     * @param object $user security user (the context decides whether it may write)
     * @param string $mediaType the type the caller sniffed (`type/subtype`, lower case, no parameters)
     *
     * @throws UploadRefused
     * @throws RetryableException a deadlock or lock wait timeout on the insert (the object written is deleted again)
     * @throws TransientStorageFailure a runner's transient failure
     * @throws DBALException a lost savepoint (commit outcome unknown, CONFLICT_002)
     */
    public function uploadFile(object $user, string $contextType, string $contextId, StoredFileSource $source, string $originalName, string $mediaType): StoredFileView;

    /**
     * After the host transaction committed: records the `file_uploaded` audit event of each upload.
     */
    public function afterCommit(StoredFileView ...$uploaded): void;
}
