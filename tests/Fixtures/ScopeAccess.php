<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures;

use Nowo\StorageKitBundle\Scope\ScopeAccessCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Scope access of the tests: `scopeadmin` manages scope `acme`.
 */
final class ScopeAccess implements ScopeAccessCheckerInterface
{
    public function canManage(?object $user, string $scope): bool
    {
        return $user instanceof UserInterface && $user->getUserIdentifier() === 'scopeadmin' && $scope === 'acme';
    }
}
