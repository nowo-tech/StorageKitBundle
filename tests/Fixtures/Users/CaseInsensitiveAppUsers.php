<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures\Users;

use Nowo\StorageKitBundle\Tests\Fixtures\AppUser;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

use function mb_strtolower;
use function trim;

/**
 * User provider of the look-alike tests that reads users the way an entity provider on a `*_ci` column does: `Alice`,
 * `ALICE` and `alice ` all load `alice` (user 7); `bob` is user 8; anyone else is unknown.
 *
 * @implements UserProviderInterface<UserInterface>
 */
final class CaseInsensitiveAppUsers implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return match (mb_strtolower(trim($identifier))) {
            'alice' => new AppUser(7, 'alice'),
            'bob'   => new AppUser(8, 'bob'),
            default => throw new UserNotFoundException(),
        };
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        throw new UnsupportedUserException();
    }

    public function supportsClass(string $class): bool
    {
        return $class === AppUser::class;
    }
}
