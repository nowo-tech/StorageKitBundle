<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures;

use Nowo\StorageKitBundle\Context\FileContextInterface;
use Nowo\StorageKitBundle\Context\FileContextTarget;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_keys;
use function array_values;
use function in_array;

/**
 * File context of the tests (`board`): files belong to a board of {@see Boards}.
 */
final class NoteContext implements FileContextInterface
{
    public int $readCalls = 0;

    public function __construct(
        private readonly Boards $boards,
    ) {
    }

    public function type(): string
    {
        return 'board';
    }

    public function readable(?object $viewer, array $ids): array
    {
        ++$this->readCalls;
        $readable = [];
        foreach ($ids as $id) {
            $board = $this->boards->boards[$id] ?? null;
            if ($board === null) {
                continue;
            }
            if ($board['anonymous'] || ($viewer instanceof UserInterface && in_array($viewer->getUserIdentifier(), $board['readers'], true))) {
                $readable[] = $id;
            }
        }

        return $readable;
    }

    public function existing(array $ids): array
    {
        return array_values(array_filter($ids, fn (string $id): bool => in_array($id, array_keys($this->boards->boards), true)));
    }

    public function writableTarget(object $user, string $id): ?FileContextTarget
    {
        $board = $this->boards->boards[$id] ?? null;
        if ($board === null || !$user instanceof UserInterface || !in_array($user->getUserIdentifier(), $board['writers'], true)) {
            return null;
        }

        return new FileContextTarget($board['scope'], 'owner-' . $id);
    }
}
