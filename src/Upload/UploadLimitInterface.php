<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use Nowo\StorageKitBundle\Context\FileContextTarget;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;

/**
 * Optional storage limit of an owner (a plan quota…), `limits.upload_limit`: asked before the objects are written; a
 * refusal stores nothing. {@see StoredFileRepository::bytesFor()} gives the usage.
 */
interface UploadLimitInterface
{
    /**
     * Whether `$bytes` more may be stored for the target's owner.
     */
    public function allows(FileContextTarget $target, int $bytes): bool;
}
