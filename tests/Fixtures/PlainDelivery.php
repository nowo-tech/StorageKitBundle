<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures;

use Nowo\StorageKitBundle\Download\DeliverableFile;
use Nowo\StorageKitBundle\Download\FileDeliveryInterface;

/**
 * A host download port of before 2.2 (no ranges): it only implements {@see FileDeliveryInterface}.
 */
final readonly class PlainDelivery implements FileDeliveryInterface
{
    public function __construct(
        private FileDeliveryInterface $inner,
    ) {
    }

    public function deliver(string $uuid, string $variant): DeliverableFile
    {
        return $this->inner->deliver($uuid, $variant);
    }
}
