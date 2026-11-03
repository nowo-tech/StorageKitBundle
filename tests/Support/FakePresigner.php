<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\StorageKitBundle\Storage\UrlPresignerInterface;

use function rawurlencode;
use function sprintf;

/**
 * Pre-signed URLs of the storage double: `https://<bucket>.storage.test/<key>?type=…&disposition=…&expires=…`.
 */
final readonly class FakePresigner implements UrlPresignerInterface
{
    public function __construct(
        private S3Settings $settings,
    ) {
    }

    public function presign(string $key, string $contentType, string $disposition, DateTimeImmutable $expires): string
    {
        return sprintf('https://%s.storage.test/%s?type=%s&disposition=%s&expires=%d', $this->settings->bucket, $key, rawurlencode($contentType), rawurlencode($disposition), $expires->getTimestamp());
    }
}
