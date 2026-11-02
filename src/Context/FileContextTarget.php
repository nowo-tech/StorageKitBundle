<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Context;

use InvalidArgumentException;
use Nowo\StorageKitBundle\Scope\ScopeResolverInterface;
use Nowo\StorageKitBundle\Scope\StorageScope;

/**
 * What an upload context belongs to: the storage scope whose bucket holds its files (null = the instance; see
 * {@see ScopeResolverInterface}) and the owner who pays for them (usage and limits,
 * a free-form key such as a project or account id; null = none).
 */
final readonly class FileContextTarget
{
    /** Longest owner key. */
    public const OWNER_MAX = 64;

    public ?string $scope;

    /**
     * @param string|null $scope storage scope key
     * @param string|null $owner usage owner key (≤ 64 characters)
     */
    public function __construct(
        ?string $scope = null,
        public ?string $owner = null,
    ) {
        $this->scope = $scope === null ? null : StorageScope::assertValid($scope);
        if ($owner !== null && ($owner === '' || mb_strlen($owner) > self::OWNER_MAX)) {
            throw new InvalidArgumentException('An owner key must be 1-64 characters.');
        }
    }
}
