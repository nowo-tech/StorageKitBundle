<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures\Users;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A second user class of the tests (shares ids with {@see Admin}).
 */
class Customer implements UserInterface
{
    /**
     * @param non-empty-string $email
     */
    public function __construct(
        private readonly int $id,
        private readonly string $email,
    ) {
    }

    public function getId(): int
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
