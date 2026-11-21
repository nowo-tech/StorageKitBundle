<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures\Ports;

use Nowo\StorageKitBundle\Download\DeliverableFile;
use Nowo\StorageKitBundle\Download\FileDelivery;

/**
 * A decorator of the `FileDelivery` service id (the class is final, so it wraps it) that host code calls: it keeps
 * decorating that id; the download port is not affected. 2.0 removed `respond()`, so it offers `deliver()`.
 */
final class LegacyDeliveryDecorator
{
    public int $calls = 0;

    public function __construct(
        private readonly FileDelivery $inner,
    ) {
    }

    public function deliver(string $uuid, string $variant): DeliverableFile
    {
        ++$this->calls;

        return $this->inner->deliver($uuid, $variant);
    }
}
