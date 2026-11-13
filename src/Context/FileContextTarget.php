<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Context;

use InvalidArgumentException;
use Nowo\StorageKitBundle\Identifier\ExactIdentifier;
use Nowo\StorageKitBundle\Scope\ScopeResolverInterface;
use Nowo\StorageKitBundle\Scope\StorageScope;

/**
 * What an upload context belongs to: the storage scope whose bucket holds its files (null = the instance; see
 * {@see ScopeResolverInterface}) and the owner who pays for them (usage and limits,
 * a free-form key such as a project or account id; null = none).
 *
 * Both keys are matched byte for byte (REQ-SEC-006, 1.5.3): `acme`, `Acme`, `acmé` and `acme ` are four owners, so an
 * owner key that is empty, longer than {@see self::OWNER_MAX} characters, invalid UTF-8, padded with white space (NBSP
 * included) or holding a control, zero-width, BOM or bidi character is refused ({@see ExactIdentifier::usable()}),
 * never trimmed or cut. The scope key keeps its ASCII syntax ({@see StorageScope::KEY_PATTERN}).
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
        if ($owner !== null && ExactIdentifier::usable($owner, self::OWNER_MAX) === null) {
            throw new InvalidArgumentException('An owner key must be 1-64 characters of valid UTF-8, without edge white space, control, zero-width or bidi characters.');
        }
    }
}
