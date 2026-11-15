<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures;

use Nowo\StorageKitBundle\Context\FileContextTarget;
use Nowo\StorageKitBundle\Context\SquareImageContextInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Square-only file context of the tests (`avatar`, 1.6): a user writes their own picture; everyone reads them.
 */
final class AvatarContext implements SquareImageContextInterface
{
    public function type(): string
    {
        return 'avatar';
    }

    public function readable(?object $viewer, array $ids): array
    {
        return $ids;
    }

    public function existing(array $ids): array
    {
        return $ids;
    }

    public function writableTarget(object $user, string $id): ?FileContextTarget
    {
        return $user instanceof UserInterface && $user->getUserIdentifier() === $id ? new FileContextTarget(null, $id) : null;
    }
}
