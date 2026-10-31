<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * S3-compatible object storage for Symfony: per-tenant buckets with encrypted credentials, image uploads for Markdown fields, reference-aware purge, bucket sync and an SSRF-guarded endpoint test.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoStorageKitBundle extends AbstractBundle
{
}
