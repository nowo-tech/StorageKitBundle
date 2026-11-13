<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures;

use Nowo\StorageKitBundle\Context\FileContextTarget;
use Nowo\StorageKitBundle\Scope\ScopeResolverInterface;

/**
 * Scope resolver of the tests that answers a fixed key, valid or not (a host resolver is not bound by the syntax).
 */
final class FixedScopeResolver implements ScopeResolverInterface
{
    public ?string $scope = null;

    public function scopeOf(FileContextTarget $target): ?string
    {
        return $this->scope;
    }
}
