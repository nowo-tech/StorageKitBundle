<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A host user entity of the tests: a database id and an e-mail identifier that can change.
 */
final class AppUser implements UserInterface
{
    /**
     * @param non-empty-string $email
     */
    public function __construct(
        private readonly int|string|null $id,
        /** @var non-empty-string */
        public string $email,
    ) {
    }

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
