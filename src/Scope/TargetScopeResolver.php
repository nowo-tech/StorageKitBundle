<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Scope;

use Nowo\StorageKitBundle\Context\FileContextTarget;

/**
 * Default scope resolver: the scope the file context put on its target.
 */
final class TargetScopeResolver implements ScopeResolverInterface
{
    /**
     * `$target->scope`.
     */
    public function scopeOf(FileContextTarget $target): ?string
    {
        return $target->scope;
    }
}
