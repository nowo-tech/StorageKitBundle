<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Scope;

use InvalidArgumentException;

use function preg_match;

/**
 * A storage scope: the owner of a set of buckets (an organisation, a tenant, a workspace… — whatever the host's
 * {@see ScopeResolverInterface} returns) or the instance (null). Scope keys appear in URLs, CSRF token ids and audit
 * events, so they are short tokens without a slash (the token-id separator).
 */
final class StorageScope
{
    /** Scope key syntax: 1–64 letters, digits, `_`, `.`, `:` or `-`. */
    public const KEY_PATTERN = '[A-Za-z0-9_.:\-]{1,64}';

    /** Label of the instance in token ids and audit events. */
    public const INSTANCE = 'instance';

    /**
     * Whether `$key` is a valid scope key.
     */
    public static function isValid(string $key): bool
    {
        return preg_match('/^' . self::KEY_PATTERN . '$/D', $key) === 1;
    }

    /**
     * `$key` validated.
     *
     * @throws InvalidArgumentException
     */
    public static function assertValid(string $key): string
    {
        if (!self::isValid($key)) {
            throw new InvalidArgumentException('A storage scope key must be 1-64 letters, digits, "_", ".", ":" or "-".');
        }

        return $key;
    }

    /**
     * Unambiguous token-id / audit suffix: `instance` or `scope/<key>` (`/` never occurs in a key).
     */
    public static function suffix(?string $scope): string
    {
        return $scope === null ? self::INSTANCE : 'scope/' . self::assertValid($scope);
    }
}
