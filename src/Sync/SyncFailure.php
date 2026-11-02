<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Sync;

use RuntimeException;

/**
 * A move failure whose message is safe to store and show (a checksum mismatch, an unusable profile): no provider
 * text, no credential.
 */
final class SyncFailure extends RuntimeException
{
}
