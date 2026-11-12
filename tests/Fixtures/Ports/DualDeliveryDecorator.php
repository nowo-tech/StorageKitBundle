<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures\Ports;

use Nowo\StorageKitBundle\Download\DeliverableFile;
use Nowo\StorageKitBundle\Download\FileDelivery;
use Nowo\StorageKitBundle\Download\FileDeliveryInterface;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * A decorator of the download port that also keeps the deprecated `respond()` for older host code (both APIs).
 */
final class DualDeliveryDecorator implements FileDeliveryInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly FileDeliveryInterface $inner,
        private readonly FileDelivery $legacy,
    ) {
    }

    public function deliver(string $uuid, string $variant): DeliverableFile
    {
        ++$this->calls;

        return $this->inner->deliver($uuid, $variant);
    }

    public function respond(StoredFile $file, string $variant): Response
    {
        ++$this->calls;

        return $this->legacy->respond($file, $variant);
    }
}
