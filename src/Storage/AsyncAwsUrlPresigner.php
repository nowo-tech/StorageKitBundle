<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Storage;

use AsyncAws\S3\Input\GetObjectRequest;
use AsyncAws\S3\S3Client;
use DateTimeImmutable;

use function max;
use function time;

/**
 * Pre-signs a GET with the async-aws signer against the browser-facing endpoint; the response overrides make the
 * bucket answer with the stored type, the configured `Content-Disposition` and a private cache bounded by the URL
 * lifetime. Signing is local: no request is sent.
 */
final readonly class AsyncAwsUrlPresigner implements UrlPresignerInterface
{
    /**
     * @param S3Client $client client configured for the browser-facing endpoint
     */
    public function __construct(
        private S3Client $client,
        private string $bucket,
    ) {
    }

    /**
     * Signed URL with response overrides.
     */
    public function presign(string $key, string $contentType, string $disposition, DateTimeImmutable $expires): string
    {
        return $this->client->presign(new GetObjectRequest([
            'Bucket'                     => $this->bucket,
            'Key'                        => $key,
            'ResponseContentType'        => $contentType,
            'ResponseContentDisposition' => $disposition,
            'ResponseCacheControl'       => 'private, max-age=' . max(0, $expires->getTimestamp() - time()),
        ]), $expires);
    }
}
