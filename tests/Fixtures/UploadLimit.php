<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures;

use Nowo\StorageKitBundle\Context\FileContextTarget;
use Nowo\StorageKitBundle\Upload\UploadLimitInterface;

/**
 * Upload limit of the tests: refuses the owner `owner-b2`.
 */
final class UploadLimit implements UploadLimitInterface
{
    public function allows(FileContextTarget $target, int $bytes): bool
    {
        return $target->owner !== 'owner-b2';
    }
}
