<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Settings;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\StorageKitBundle\Storage\StorageProvider;
use SensitiveParameter;

use function is_string;
use function trim;

/**
 * Reads and writes the instance connection kept in the `storage` Instance Settings section
 * ({@see StorageSettingsSection}). The secret is read only through {@see InstanceSettingsInterface::getSecret()} and
 * never leaves this class except inside an {@see S3Settings} (whose debug output masks it) or a move's switch.
 *
 * @phpstan-type Connection array{provider: string, endpoint: string|null, public_endpoint: string|null, region: string, bucket: string, prefix: string, access_key: string, path_style: bool}
 */
final readonly class InstanceStorageSettings
{
    /**
     * @param InstanceSettingsInterface $settings Instance Settings store
     * @param bool $restricted whether the instance endpoint goes through the SSRF guard (`endpoints.guard_instance_profile`)
     */
    public function __construct(
        private InstanceSettingsInterface $settings,
        private bool $restricted = true,
    ) {
    }

    /**
     * The selected provider, or null when none is selected (uploads off) or the stored value is unknown.
     */
    public function provider(): ?string
    {
        $provider = $this->settings->getString(StorageSettingsSection::NAME . '.provider');

        return StorageProvider::isValid($provider) ? $provider : null;
    }

    /**
     * The plain (non-secret) connection fields, or null when no provider is selected.
     *
     * @return Connection|null
     */
    public function connection(): ?array
    {
        $provider = $this->provider();
        if ($provider === null) {
            return null;
        }
        $value = fn (string $name): string => trim((string) $this->settings->getString(StorageSettingsSection::NAME . '.' . $name));

        return [
            'provider'        => $provider,
            'endpoint'        => $value('endpoint') === '' ? null : $value('endpoint'),
            'public_endpoint' => $value('public_endpoint') === '' ? null : $value('public_endpoint'),
            'region'          => $value('region') === '' ? S3Settings::DEFAULT_REGION : $value('region'),
            'bucket'          => $value('bucket'),
            'prefix'          => $value('prefix'),
            'access_key'      => $value('access_key'),
            'path_style'      => $this->settings->getBool(StorageSettingsSection::NAME . '.path_style'),
        ];
    }

    /**
     * The stored secret access key (decrypted), or null.
     */
    public function secret(): ?string
    {
        return $this->settings->getSecret(StorageSettingsSection::NAME . '.secret');
    }

    /**
     * The connection as validated settings, or null when no provider is selected or a field is missing / malformed
     * (uploads stay off; the section shows a notice).
     */
    public function settings(): ?S3Settings
    {
        $connection = $this->connection();
        $secret     = $this->secret();
        if ($connection === null || !is_string($secret)) {
            return null;
        }
        $endpoints = StorageProvider::usesEndpoint($connection['provider']);
        if ($endpoints && $connection['endpoint'] === null) {
            return null;
        }
        try {
            return new S3Settings(
                bucket: $connection['bucket'],
                accessKey: $connection['access_key'],
                secret: $secret,
                region: $connection['region'],
                endpoint: $endpoints ? $connection['endpoint'] : null,
                publicEndpoint: $endpoints ? $connection['public_endpoint'] : null,
                prefix: $connection['prefix'],
                pathStyle: $endpoints && $connection['path_style'],
                restricted: $this->restricted,
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Replaces the section with another connection (a move's switch). Validated by Instance Settings (all or
     * nothing); fields of the other provider are written too, so the section shows exactly the new bucket.
     *
     * @param Connection $connection plain fields
     * @param string $secret secret access key (encrypted by Instance Settings)
     */
    public function write(array $connection, #[SensitiveParameter] string $secret): void
    {
        $this->settings->save(StorageSettingsSection::NAME, [
            'provider'        => $connection['provider'],
            'endpoint'        => $connection['endpoint'],
            'public_endpoint' => $connection['public_endpoint'],
            'region'          => $connection['region'],
            'bucket'          => $connection['bucket'],
            'prefix'          => $connection['prefix'],
            'access_key'      => $connection['access_key'],
            'path_style'      => $connection['path_style'],
            'secret'          => $secret,
        ], SettingsChangedEvent::ORIGIN_API);
    }
}
