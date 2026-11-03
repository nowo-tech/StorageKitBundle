<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Scope;

/**
 * Fail-closed default while no `scopes.access_checker` is configured: nobody manages a scope.
 */
final class DenyAllScopeAccessChecker implements ScopeAccessCheckerInterface
{
    /**
     * Always false.
     */
    public function canManage(?object $user, string $scope): bool
    {
        return false;
    }
}
