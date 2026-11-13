<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Scope;

use Nowo\StorageKitBundle\Context\FileContextTarget;

/**
 * Which scope's bucket an upload of a context goes to (`scopes.resolver`). The default
 * ({@see TargetScopeResolver}) returns the scope the context itself reported; a host can map its own owner model
 * (a project → its organisation, a workspace → its parent…) here instead. Null = the instance profile.
 */
interface ScopeResolverInterface
{
    /**
     * The scope key of the target (a valid {@see StorageScope} key), or null for the instance. Matched byte for byte
     * (`Acme` is not `acme`); since 1.5.3 a key outside the syntax turns the target's uploads off.
     */
    public function scopeOf(FileContextTarget $target): ?string;
}
