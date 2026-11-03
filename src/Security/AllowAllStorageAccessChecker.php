<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Security;

/**
 * DEV / DEMO only (`security.allow_unauthenticated: true`, refused without kernel.debug): everybody may open the
 * instance pages.
 */
final class AllowAllStorageAccessChecker implements StorageAccessCheckerInterface
{
    /**
     * Always true.
     */
    public function canAccess(?object $user): bool
    {
        return true;
    }
}
