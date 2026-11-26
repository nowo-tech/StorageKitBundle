<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use Doctrine\DBAL\Exception\RetryableException;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\TransactionContract\TransientStorageFailure;

/**
 * Port that attaches a generated image variant (`thumb` or `preview`) to a stored file (2.2): a thumbnail of an image,
 * the first page of a PDF, an unfurl image. Aliased to {@see FileUploader}.
 *
 * The file is found by its public id **and** its context, both compared byte for byte (an unknown, expired or foreign
 * file is refused like an unknown context). The bytes must be a raster image (PNG, JPEG, GIF or WebP) whose magic bytes
 * match `$mediaType`, at most `upload.max_bytes` and `upload.max_pixels`: a variant is served `inline`, so only an image
 * the caller re-encoded belongs here ({@see ImageThumbnailerInterface}).
 *
 * Concurrency (REQ-RUNTIME-003): the object is written first, under a new random key, with no lock held; then one short
 * unit locks the file row (`SELECT … FOR UPDATE`), checks it is still there, in that context and not expired, and
 * writes the variant list and the byte total. The object the variant replaces is deleted after the commit: at once
 * outside a transaction; inside yours (the unit is then a savepoint) only when you call {@see self::afterVariantCommit()}
 * after your commit, and on a rollback call {@see self::afterVariantRollback()} to delete the new object (2.2, audit
 * ZCK-SK-L1; a view you drop without either keeps both objects, the unused one unreferenced). Two writers
 * of the same variant serialize on the row: the last one wins and no object is left behind. A failed unit deletes the
 * new object again and rethrows the failure (a lock conflict is a retry-safe CONFLICT_001).
 */
interface FileVariantWriterInterface
{
    /**
     * Stores `$bytes` as variant `$variant` of the file and returns the file's view.
     *
     * @param string $variant {@see StoredFile::VARIANT_THUMB} or {@see StoredFile::VARIANT_PREVIEW}
     * @param string $mediaType `image/png`, `image/jpeg`, `image/gif` or `image/webp`
     *
     * @throws UploadRefused `context` (no such file in that context), `type`, `size`, `pixels`, `empty`, `off` or `storage`
     * @throws RetryableException|TransientStorageFailure a lock conflict on the row (the new object is deleted again)
     */
    public function putVariant(string $uuid, string $contextType, string $contextId, string $variant, string $bytes, string $mediaType): StoredFileView;

    /**
     * After your transaction committed: deletes the objects the variants written inside it replaced.
     */
    public function afterVariantCommit(StoredFileView ...$written): void;

    /**
     * After your transaction rolled back: deletes the objects those variant writes stored (unused again).
     */
    public function afterVariantRollback(StoredFileView ...$written): void;
}
