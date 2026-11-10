<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures\Users;

use DateTimeImmutable;
use LogicException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A user of the backfill tests with an account creation date (null: `getCreatedAt()` throws).
 */
final class DatedUser implements UserInterface
{
    /**
     * @param non-empty-string $email
     */
    public function __construct(
        private readonly int $id,
        private readonly string $email,
        private readonly ?DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt ?? throw new LogicException('no creation date');
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
