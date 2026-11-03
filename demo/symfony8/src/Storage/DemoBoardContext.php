<?php

declare(strict_types=1);

namespace App\Storage;

use Nowo\StorageKitBundle\Context\FileContextInterface;
use Nowo\StorageKitBundle\Context\FileContextTarget;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_values;
use function in_array;

/**
 * The demo's only upload context (`board` / `demo`): every signed-in user reads and writes it; files are charged to
 * the owner `demo`. A real application maps its own permissions (a project, an issue, a wiki page…) here.
 */
final class DemoBoardContext implements FileContextInterface
{
    public const TYPE = 'board';

    public const ID = 'demo';

    public function type(): string
    {
        return self::TYPE;
    }

    public function readable(?object $viewer, array $ids): array
    {
        return $viewer instanceof UserInterface ? array_values(array_filter($ids, static fn (string $id): bool => $id === self::ID)) : [];
    }

    public function existing(array $ids): array
    {
        return in_array(self::ID, $ids, true) ? [self::ID] : [];
    }

    public function writableTarget(object $user, string $id): ?FileContextTarget
    {
        return $id === self::ID && $user instanceof UserInterface ? new FileContextTarget(null, 'demo') : null;
    }
}
