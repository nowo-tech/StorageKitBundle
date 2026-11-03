<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Storage;

use function in_array;

/**
 * The storage providers a connection can use. Both speak the S3 API; they differ in the fields an operator fills in:
 *
 * - {@see self::AWS_S3}: Amazon S3 itself. Region, bucket, prefix and keys; the endpoint is derived from the region
 *   (virtual-hosted style), so no endpoint, public endpoint or path-style field is shown or used.
 * - {@see self::S3_COMPATIBLE}: any S3-compatible service (Cloudflare R2, Backblaze B2, Wasabi, Scaleway, RustFS,
 *   MinIO…): the same fields plus the endpoint (SSRF-guarded), an optional public endpoint and the path-style switch.
 *
 * The values of the other provider's fields are kept when the provider changes (they are hidden, not cleared), but
 * they are never used: {@see self::usesEndpoint()}.
 */
final class StorageProvider
{
    /** Amazon S3 (endpoint derived from the region). */
    public const AWS_S3 = 'aws_s3';

    /** An S3-compatible service reached through an explicit endpoint. */
    public const S3_COMPATIBLE = 's3_compatible';

    /** Every provider, in display order. */
    public const ALL = [self::AWS_S3, self::S3_COMPATIBLE];

    /** Fields shown for every provider (S3 credentials and location). */
    public const COMMON_FIELDS = ['region', 'bucket', 'prefix', 'access_key', 'secret'];

    /** Fields shown only for {@see self::S3_COMPATIBLE}. */
    public const ENDPOINT_FIELDS = ['endpoint', 'public_endpoint', 'path_style'];

    /**
     * Whether a value names a known provider.
     */
    public static function isValid(mixed $provider): bool
    {
        return in_array($provider, self::ALL, true);
    }

    /**
     * Whether the provider uses the endpoint, public endpoint and path-style fields.
     */
    public static function usesEndpoint(string $provider): bool
    {
        return $provider === self::S3_COMPATIBLE;
    }

    /**
     * Translation key of the provider label (domain NowoStorageKitBundle).
     */
    public static function labelKey(string $provider): string
    {
        return 'provider.' . $provider;
    }

    /**
     * Choice list for a form: label key => provider.
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::ALL as $provider) {
            $choices[self::labelKey($provider)] = $provider;
        }

        return $choices;
    }
}
