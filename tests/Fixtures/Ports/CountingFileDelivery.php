<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures\Ports;

use Nowo\StorageKitBundle\Download\DeliverableFile;
use Nowo\StorageKitBundle\Download\FileDeliveryInterface;

/**
 * A host's own download port (1.5): wraps the bundle's delivery and counts the calls (an antivirus or quota check
 * would sit here).
 */
final class CountingFileDelivery implements FileDeliveryInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly FileDeliveryInterface $inner,
    ) {
    }

    public function deliver(string $uuid, string $variant): DeliverableFile
    {
        ++$this->calls;

        return $this->inner->deliver($uuid, $variant);
    }
}
