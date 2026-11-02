<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Storage;

use AsyncAws\Core\Credentials\ConfigurationProvider;
use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use Nowo\StorageKitBundle\Security\EndpointGuard;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_filter;

/**
 * The production storage: an S3-compatible bucket through `league/flysystem-async-aws-s3` (async-aws: a small signer
 * and HTTP client on Symfony HttpClient instead of the full AWS SDK). Credentials come only from the settings (no
 * instance-metadata or environment fallback); pre-signed URLs are signed for the browser-facing endpoint when there is
 * one; objects are written private ({@see PrivateVisibilityConverter}); restricted settings talk through the guarded
 * client of {@see EndpointGuard} (SEC-STORAGE-SSRF).
 */
final readonly class S3StorageConnectionFactory implements StorageConnectionFactoryInterface
{
    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private EndpointGuard $guard,
        private ?HttpClientInterface $httpClient = null,
    ) {
    }

    /**
     * Opens the bucket.
     */
    public function connect(S3Settings $settings): StorageConnection
    {
        $client = $this->client($settings, $settings->endpoint, $this->guard->httpClient($this->httpClient, $settings));
        $public = $settings->publicEndpoint === null ? $client : $this->client($settings, $settings->publicEndpoint, null);

        return new StorageConnection(
            new Filesystem(new AsyncAwsS3Adapter($client, $settings->bucket, $settings->prefix, new PrivateVisibilityConverter())),
            new AsyncAwsUrlPresigner($public, $settings->bucket),
            $settings,
        );
    }

    /**
     * @param HttpClientInterface|null $http null for a signing-only client (never sends a request)
     */
    private function client(S3Settings $settings, ?string $endpoint, ?HttpClientInterface $http): S3Client
    {
        return new S3Client(array_filter([
            'region'            => $settings->region,
            'endpoint'          => $endpoint,
            'accessKeyId'       => $settings->accessKey,
            'accessKeySecret'   => $settings->secret,
            'pathStyleEndpoint' => $settings->usesPathStyle() ? 'true' : 'false',
            // S3-compatible services differ in their support of aws-chunked uploads: send plain bodies.
            'sendChunkedBody' => 'false',
        ], static fn (?string $value): bool => $value !== null), new ConfigurationProvider(), $http);
    }
}
