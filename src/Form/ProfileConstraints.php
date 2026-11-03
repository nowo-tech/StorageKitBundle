<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Form;

use InvalidArgumentException;
use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Nowo\StorageKitBundle\Security\EndpointGuard;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function is_string;
use function preg_match;
use function trim;

/**
 * Validation of the bucket fields, shared by the scope / move profile form ({@see StorageProfileType}) and the
 * `storage` Instance Settings section. Messages are keys of the NowoStorageKitBundle domain and never repeat the
 * value. Empty values pass (completeness is checked separately).
 */
final class ProfileConstraints
{
    /**
     * Endpoint URL: well-formed http(s) origin, and — when restricted — a public host according to the SSRF guard.
     *
     * @return list<Constraint>
     */
    public static function endpoint(?EndpointGuard $guard, bool $restricted): array
    {
        return [new Length(max: 255), new Callback(static function (mixed $value, ExecutionContextInterface $context) use ($guard, $restricted): void {
            if (!is_string($value) || trim($value) === '') {
                return;
            }
            try {
                S3Settings::endpoint($value);
            } catch (InvalidArgumentException) {
                self::violation($context, 'profile.error.' . EndpointGuard::INVALID);

                return;
            }
            $reason = $restricted && $guard instanceof EndpointGuard ? $guard->check($value) : null;
            if ($reason !== null) {
                self::violation($context, 'profile.error.' . $reason);
            }
        })];
    }

    /**
     * Region name.
     *
     * @return list<Constraint>
     */
    public static function region(): array
    {
        return [new Length(max: S3Settings::REGION_MAX), self::pattern(S3Settings::REGION_PATTERN, 'profile.error.region')];
    }

    /**
     * Bucket name (S3 naming rules, no "..").
     *
     * @return list<Constraint>
     */
    public static function bucket(): array
    {
        return [self::pattern(S3Settings::BUCKET_PATTERN, 'profile.error.bucket'), self::pattern('/\.\./', 'profile.error.bucket', false)];
    }

    /**
     * Key prefix (no traversal segment, safe characters only).
     *
     * @return list<Constraint>
     */
    public static function prefix(): array
    {
        return [new Callback(static function (mixed $prefix, ExecutionContextInterface $context): void {
            try {
                S3Settings::prefix(is_string($prefix) ? $prefix : '');
            } catch (InvalidArgumentException) {
                self::violation($context, 'profile.error.prefix');
            }
        })];
    }

    /**
     * Access key id (printable, no spaces).
     *
     * @return list<Constraint>
     */
    public static function accessKey(): array
    {
        return [new Length(max: S3Settings::ACCESS_KEY_MAX), self::pattern('/^[^\x00-\x20\x7F]*$/', 'profile.error.access_key')];
    }

    /**
     * Secret access key (printable).
     *
     * @return list<Constraint>
     */
    public static function secret(): array
    {
        return [new Length(max: S3Settings::SECRET_MAX), self::pattern('/^[^\x00-\x1F\x7F]*$/', 'profile.error.secret')];
    }

    /**
     * A non-empty string must (or must not) match a pattern.
     */
    public static function pattern(string $regex, string $key, bool $match = true): Callback
    {
        return new Callback(static function (mixed $value, ExecutionContextInterface $context) use ($regex, $key, $match): void {
            if (!is_string($value) || $value === '') {
                return;
            }
            if ((preg_match($regex, $value) === 1) !== $match) {
                self::violation($context, $key);
            }
        });
    }

    private static function violation(ExecutionContextInterface $context, string $key): void
    {
        $context->buildViolation($key)->setTranslationDomain(NowoStorageKitBundle::TRANSLATION_DOMAIN)->addViolation();
    }
}
