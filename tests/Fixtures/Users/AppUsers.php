<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures\Users;

use DateTimeImmutable;
use Nowo\StorageKitBundle\Tests\Fixtures\AppUser;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * User provider of the backfill tests: `alice` is user 7, `bob` is user 8, `carol` has no id, `dave` (user 9) was
 * created tomorrow, `erin` (user 10) yesterday, `fred` (user 11) has a throwing `getCreatedAt()`; anyone else is
 * unknown.
 *
 * @implements UserProviderInterface<UserInterface>
 */
final class AppUsers implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return match ($identifier) {
            'alice' => new AppUser(7, 'alice'),
            'bob'   => new AppUser(8, 'bob'),
            'carol' => new AppUser(null, 'carol'),
            'dave'  => new DatedUser(9, 'dave', new DateTimeImmutable('+1 day')),
            'erin'  => new DatedUser(10, 'erin', new DateTimeImmutable('-1 day')),
            'fred'  => new DatedUser(11, 'fred', null),
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
