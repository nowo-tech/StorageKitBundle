<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Security;

/**
 * Access to the instance Storage and Move pages (REQ-UI-002). Replace the role checker with
 * `nowo_storage_kit.security.access_checker: <service id>`.
 */
interface StorageAccessCheckerInterface
{
    /**
     * True when the user may manage the instance buckets.
     *
     * @param object|null $user security user (null = anonymous)
     */
    public function canAccess(?object $user): bool;
}
