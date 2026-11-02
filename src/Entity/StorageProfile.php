<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;
use Nowo\StorageKitBundle\Scope\ScopeResolverInterface;
use Nowo\StorageKitBundle\Scope\StorageScope;
use Nowo\StorageKitBundle\Storage\S3Settings;
use SensitiveParameter;
use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * An S3-compatible bucket files are stored in, owned by a scope (`scope` key, see
 * {@see ScopeResolverInterface}) or by the instance (scope null).
 *
 * Two sources: `database` rows hold their own fields, edited on the Storage / Move pages — the secret is encrypted at
 * rest by DoctrineEncrypt and write-only (a blank value keeps the stored one); `config` rows only name a profile of the
 * `nowo_storage_kit.profiles` tree (ops configuration, credentials from the environment) so files can point at it.
 *
 * `enabled` false turns uploads off for its owner (existing files stay readable). Every {@see StoredFile} remembers its
 * profile, so downloads, purges and moves keep using the right bucket. `state`: an owner has one `active` profile (the
 * one uploads go to), at most one `target` (the bucket a move copies to) and any number of `retired` ones (the buckets
 * moved away from: kept so a file written there during the switch still downloads).
 */
#[ORM\Entity]
#[ORM\Table(name: 'storage_kit_profile')]
#[ORM\UniqueConstraint(name: 'uniq_storage_kit_profile_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_storage_kit_profile_owner', columns: ['scope', 'state'])]
class StorageProfile
{
    /** The owner's profile uploads go to. */
    public const STATE_ACTIVE = 'active';

    /** The bucket a move copies to. */
    public const STATE_TARGET = 'target';

    /** A bucket moved away from. */
    public const STATE_RETIRED = 'retired';

    /** Fields edited on the pages. */
    public const SOURCE_DATABASE = 'database';

    /** Settings from the `profiles` configuration tree. */
    public const SOURCE_CONFIG = 'config';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private string $uuid;

    #[ORM\Column]
    private bool $enabled = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $endpoint = null;

    #[ORM\Column(name: 'public_endpoint', length: 255, nullable: true)]
    private ?string $publicEndpoint = null;

    #[ORM\Column(length: 32)]
    private string $region = S3Settings::DEFAULT_REGION;

    #[ORM\Column(length: 63)]
    private string $bucket = '';

    #[ORM\Column(length: 191)]
    private string $prefix = '';

    #[ORM\Column(name: 'access_key', length: 191)]
    private string $accessKey = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Encrypted]
    private ?string $secret = null;

    #[ORM\Column(name: 'path_style')]
    private bool $pathStyle = false;

    #[ORM\Column(name: 'tested_at', nullable: true)]
    private ?DateTimeImmutable $testedAt = null;

    #[ORM\Column(name: 'test_ok', nullable: true)]
    private ?bool $testOk = null;

    #[ORM\Column(name: 'test_public_read')]
    private bool $testPublicRead = false;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private DateTimeImmutable $updatedAt;

    /**
     * @param string|null $scope owner scope key (null = the instance)
     * @param string $state {@see self::STATE_ACTIVE} / {@see self::STATE_TARGET}
     * @param string|null $configName the `profiles` entry of a config row (null = a database row)
     */
    public function __construct(
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $scope = null,
        #[ORM\Column(length: 16)]
        private string $state = self::STATE_ACTIVE,
        #[ORM\Column(name: 'config_name', length: 64, nullable: true)]
        private ?string $configName = null,
    ) {
        if ($scope !== null) {
            StorageScope::assertValid($scope);
        }
        $this->uuid      = Uuid::v4()->toRfc4122();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        if ($configName !== null) {
            $this->enabled = true;
        }
    }

    /**
     * Database id.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Public id (CLI, audit events).
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * The owner scope; null = the instance.
     */
    public function getScope(): ?string
    {
        return $this->scope;
    }

    /**
     * {@see self::SOURCE_CONFIG} or {@see self::SOURCE_DATABASE}.
     */
    public function getSource(): string
    {
        return $this->configName === null ? self::SOURCE_DATABASE : self::SOURCE_CONFIG;
    }

    /**
     * The `profiles` entry of a config row.
     */
    public function getConfigName(): ?string
    {
        return $this->configName;
    }

    /**
     * Lifecycle state.
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * The profile uploads of its owner go to.
     */
    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    /**
     * The bucket a move copies to.
     */
    public function isTarget(): bool
    {
        return $this->state === self::STATE_TARGET;
    }

    /**
     * *Switch*: the target becomes the owner's profile (uploads on).
     */
    public function activate(): void
    {
        $this->state     = self::STATE_ACTIVE;
        $this->enabled   = true;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * *Switch*: the active profile is retired (kept for its remaining objects).
     */
    public function retire(): void
    {
        $this->state     = self::STATE_RETIRED;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Uploads are on.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Bucket, key and secret are set (config rows: the configuration decides).
     */
    public function isComplete(): bool
    {
        return $this->configName !== null || ($this->bucket !== '' && $this->accessKey !== '' && $this->hasSecret());
    }

    /**
     * The connection settings of a database row (`restricted`: through the SSRF guard).
     *
     * @throws InvalidArgumentException when the stored fields are malformed or incomplete
     */
    public function settings(bool $restricted): S3Settings
    {
        return new S3Settings(
            bucket: $this->bucket,
            accessKey: $this->accessKey,
            secret: (string) $this->secret,
            region: $this->region,
            endpoint: $this->endpoint,
            publicEndpoint: $this->publicEndpoint,
            prefix: $this->prefix,
            pathStyle: $this->pathStyle,
            restricted: $restricted,
        );
    }

    /**
     * Saves the form's fields. A null / blank secret keeps the stored one (write-only field); DoctrineEncrypt encrypts
     * on flush, so the raw value is set here.
     */
    public function configure(bool $enabled, ?string $endpoint, ?string $publicEndpoint, string $region, string $bucket, string $prefix, string $accessKey, #[SensitiveParameter] ?string $secret, bool $pathStyle): void
    {
        $blank                = static fn (?string $value): ?string => $value === null || trim($value) === '' ? null : trim($value);
        $this->enabled        = $enabled;
        $this->endpoint       = $blank($endpoint);
        $this->publicEndpoint = $blank($publicEndpoint);
        $this->region         = $blank($region) ?? S3Settings::DEFAULT_REGION;
        $this->bucket         = trim($bucket);
        $this->prefix         = trim($prefix, " /\t");
        $this->accessKey      = trim($accessKey);
        $this->secret         = $blank($secret) ?? $this->secret;
        $this->pathStyle      = $pathStyle;
        $this->updatedAt      = new DateTimeImmutable();
    }

    /**
     * Records the outcome of *Test connection*.
     */
    public function recordTest(bool $ok, bool $publicRead): void
    {
        $this->testedAt       = new DateTimeImmutable();
        $this->testOk         = $ok;
        $this->testPublicRead = $publicRead;
    }

    /**
     * DoctrineEncrypt reads the column through this getter (raw value: never rendered).
     */
    public function getSecret(): ?string
    {
        return $this->secret;
    }

    /**
     * DoctrineEncrypt writes the column through this setter.
     */
    public function setSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->secret = $secret;
    }

    /**
     * A secret is stored.
     */
    public function hasSecret(): bool
    {
        return $this->secret !== null && $this->secret !== '';
    }

    /**
     * Server-side endpoint.
     */
    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    /**
     * Browser-facing endpoint.
     */
    public function getPublicEndpoint(): ?string
    {
        return $this->publicEndpoint;
    }

    /**
     * Region token.
     */
    public function getRegion(): string
    {
        return $this->region;
    }

    /**
     * Bucket name.
     */
    public function getBucket(): string
    {
        return $this->bucket;
    }

    /**
     * Key prefix.
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Access key id.
     */
    public function getAccessKey(): string
    {
        return $this->accessKey;
    }

    /**
     * Path-style addressing.
     */
    public function isPathStyle(): bool
    {
        return $this->pathStyle;
    }

    /**
     * Last *Test connection*.
     */
    public function getTestedAt(): ?DateTimeImmutable
    {
        return $this->testedAt;
    }

    /**
     * Outcome of the last test (null = never tested).
     */
    public function getTestOk(): ?bool
    {
        return $this->testOk;
    }

    /**
     * The last test found the bucket publicly readable.
     */
    public function isTestPublicRead(): bool
    {
        return $this->testPublicRead;
    }

    /**
     * Creation time.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Last change.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
