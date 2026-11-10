<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\PersonalData;

use Stringable;

use function is_int;
use function is_string;
use function method_exists;
use function strlen;
use function trim;

/**
 * Default {@see UploaderKeyResolverInterface}: the user's `getId()` when the user object has one and it returns an
 * integer, a non-empty string or a stringable value (a `Uuid`); null otherwise (an in-memory user, a user without id),
 * and null for a key longer than {@see UploaderKeyResolverInterface::MAX_LENGTH}.
 *
 * Use your own resolver when the id is not stable (a user re-created on each login) or when several user classes share
 * one bucket with overlapping ids (prefix the key with the class, for example).
 */
final readonly class UserIdUploaderKeyResolver implements UploaderKeyResolverInterface
{
    /**
     * The user's id as a string, or null.
     */
    public function keyOf(object $user): ?string
    {
        if (!method_exists($user, 'getId')) {
            return null;
        }
        $id = $user->getId();
        if (is_int($id)) {
            return (string) $id;
        }
        if (!is_string($id) && !$id instanceof Stringable) {
            return null;
        }
        $key = trim((string) $id);

        return $key === '' || strlen($key) > self::MAX_LENGTH ? null : $key;
    }
}
