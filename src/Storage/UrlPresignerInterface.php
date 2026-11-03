<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Storage;

use DateTimeImmutable;

/**
 * A short-lived signed GET URL for one object of a private bucket, with the response headers the bucket must send
 * (type from the stored row, the configured disposition, a private cache). Never a public URL.
 */
interface UrlPresignerInterface
{
    /**
     * Pre-signs a GET of `$key` (full key, prefix included) valid until `$expires`.
     */
    public function presign(string $key, string $contentType, string $disposition, DateTimeImmutable $expires): string;
}
