<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use Nowo\StorageKitBundle\Profile\ConfigProfileProviderInterface;
use Nowo\StorageKitBundle\Profile\ConfigProfiles;
use Nowo\StorageKitBundle\Storage\S3Settings;

/**
 * A host's own source of the named buckets (a DDD host reading them from its secret manager): `primary` streamed by
 * the application, `cdn` delivered by redirect. Fake placeholder credentials; counts the lookups.
 */
final class HostBucketProfiles implements ConfigProfileProviderInterface
{
    public int $lookups = 0;

    public function names(): array
    {
        return ['primary', 'cdn'];
    }

    public function has(string $name): bool
    {
        return $name === 'primary' || $name === 'cdn';
    }

    public function delivery(string $name): string
    {
        return $name === 'cdn' ? ConfigProfiles::DELIVERY_REDIRECT : ConfigProfiles::DELIVERY_PROXY;
    }

    public function settings(string $name): ?S3Settings
    {
        ++$this->lookups;
        if (!$this->has($name)) {
            return null;
        }

        return new S3Settings(
            bucket: $name === 'cdn' ? 'cdn-files' : 'host-files',
            accessKey: 'host-key',
            secret: 'REDACTED-TEST',
            endpoint: $name === 'cdn' ? 'https://cdn.example.test' : 'http://minio:9000',
            prefix: 'app',
            pathStyle: true,
        );
    }
}
