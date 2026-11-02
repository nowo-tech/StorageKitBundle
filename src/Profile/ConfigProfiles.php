<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Profile;

use InvalidArgumentException;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;

use function array_keys;
use function is_string;
use function trim;

/**
 * The `nowo_storage_kit.profiles` tree: named buckets set by operations (credentials from the environment, e.g.
 * `dsn: '%env(STORAGE_KIT_DSN)%'`). They are trusted configuration — not checked by the SSRF guard — and may be served
 * by redirect to pre-signed URLs (`delivery: redirect`, their origin then goes into the CSP `img-src`). A malformed
 * entry (for instance an empty env var) makes the profile unusable (uploads off) instead of failing the request; the
 * log says which profile, never the value.
 *
 * @phpstan-type ProfileConfig array{dsn: string|null, endpoint: string|null, public_endpoint: string|null, region: string, bucket: string|null, prefix: string, access_key: string|null, secret: string|null, path_style: bool, delivery: string}
 */
final readonly class ConfigProfiles
{
    /** Redirect downloads to pre-signed URLs of the bucket. */
    public const DELIVERY_REDIRECT = 'redirect';

    /** Stream downloads through the application. */
    public const DELIVERY_PROXY = 'proxy';

    /**
     * @param array<string, ProfileConfig> $profiles name => configuration
     */
    public function __construct(
        #[SensitiveParameter]
        private array $profiles = [],
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Configured profile names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->profiles);
    }

    /**
     * Whether a profile is configured.
     */
    public function has(string $name): bool
    {
        return isset($this->profiles[$name]);
    }

    /**
     * {@see self::DELIVERY_REDIRECT} or {@see self::DELIVERY_PROXY}.
     */
    public function delivery(string $name): string
    {
        return ($this->profiles[$name]['delivery'] ?? self::DELIVERY_PROXY) === self::DELIVERY_REDIRECT ? self::DELIVERY_REDIRECT : self::DELIVERY_PROXY;
    }

    /**
     * The settings of a profile, or null when it is unknown or malformed (logged, without the value).
     */
    public function settings(string $name): ?S3Settings
    {
        $profile = $this->profiles[$name] ?? null;
        if ($profile === null) {
            return null;
        }
        try {
            $dsn = $profile['dsn'];
            if (is_string($dsn) && trim($dsn) !== '') {
                return S3Settings::fromDsn($dsn);
            }

            return new S3Settings(
                bucket: (string) $profile['bucket'],
                accessKey: (string) $profile['access_key'],
                secret: (string) $profile['secret'],
                region: $profile['region'],
                endpoint: $profile['endpoint'],
                publicEndpoint: $profile['public_endpoint'],
                prefix: $profile['prefix'],
                pathStyle: $profile['path_style'],
            );
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Storage profile "{profile}" is not usable: {reason}', ['profile' => $name, 'reason' => $e->getMessage()]);

            return null;
        }
    }
}
