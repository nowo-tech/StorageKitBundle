<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default checker: the user holds any of the configured roles. The configuration refuses an empty role list, and an
 * empty list given here denies everybody (fail closed, P1). Anonymous users are denied.
 */
final readonly class RoleAccessChecker implements StorageAccessCheckerInterface
{
    /**
     * @param list<string> $roles roles (any of them)
     */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $roles,
    ) {
    }

    /**
     * Any role granted.
     */
    public function canAccess(?object $user): bool
    {
        if ($user === null) {
            return false;
        }
        foreach ($this->roles as $role) {
            if ($role !== '' && $this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
