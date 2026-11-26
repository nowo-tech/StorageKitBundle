<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures;

use Nowo\StorageKitBundle\Context\FileContextTarget;
use Nowo\StorageKitBundle\Context\GenericFileContextInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_key_exists;
use function array_values;
use function in_array;

/**
 * Generic file context of the tests (2.2). `chat` (the default) never lets StorageKit's own route serve its files
 * (`readable()` answers `[]`, like ChatKit's); `docs` lets its members read them through that route.
 */
final class ChatContext implements GenericFileContextInterface
{
    /** @var array<string, list<string>> context id => members */
    public array $members = [
        'c1' => ['alice', 'bob'],
        'c2' => ['bob'],
        'c9' => ['alice'],
    ];

    /** @var list<string> */
    public array $types = ['application/pdf', 'text/plain', 'image/png', 'image/svg+xml', 'text/html', 'audio/mpeg', 'video/mp4'];

    public int $maxBytes = 1024 * 1024;

    public function __construct(
        private readonly string $type = 'chat',
        private readonly bool $readableByMembers = false,
    ) {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function readable(?object $viewer, array $ids): array
    {
        if (!$this->readableByMembers || !$viewer instanceof UserInterface) {
            return [];
        }
        $readable = [];
        foreach ($ids as $id) {
            if (in_array($viewer->getUserIdentifier(), $this->members[$id] ?? [], true)) {
                $readable[] = $id;
            }
        }

        return $readable;
    }

    public function existing(array $ids): array
    {
        return array_values(array_filter($ids, fn (string $id): bool => array_key_exists($id, $this->members)));
    }

    public function writableTarget(object $user, string $id): ?FileContextTarget
    {
        if (!$user instanceof UserInterface || !in_array($user->getUserIdentifier(), $this->members[$id] ?? [], true)) {
            return null;
        }

        return new FileContextTarget(null, $id === 'c2' ? 'owner-b2' : 'conversation-' . $id);
    }

    public function acceptedTypes(): array
    {
        return $this->types;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }
}
