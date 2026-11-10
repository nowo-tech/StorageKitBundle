<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures;

use Nowo\StorageKitBundle\PersonalData\UploaderKeyResolverInterface;

/**
 * Custom uploader key resolver of the tests: `user:<id>`.
 */
final class PrefixedUploaderKeys implements UploaderKeyResolverInterface
{
    public function keyOf(object $user): ?string
    {
        return $user instanceof AppUser && $user->getId() !== null ? 'user:' . $user->getId() : null;
    }
}
