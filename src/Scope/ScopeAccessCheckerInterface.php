<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Scope;

/**
 * Who may set up a scope's storage (its Storage and Move pages): `scopes.access_checker`. Denied users get a 404
 * (the scope's existence is not disclosed).
 */
interface ScopeAccessCheckerInterface
{
    /**
     * Whether `$user` may manage the buckets of `$scope`.
     *
     * @param object|null $user security user (null = anonymous)
     */
    public function canManage(?object $user, string $scope): bool;
}
