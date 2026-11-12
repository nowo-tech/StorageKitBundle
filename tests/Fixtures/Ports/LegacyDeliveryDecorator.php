<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures\Ports;

use Nowo\StorageKitBundle\Download\FileDelivery;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * A 1.4-style decorator of the deprecated `FileDelivery` service id: only `respond()` (the class is final, so it
 * wraps it). Host code that calls it keeps getting it; the download port is not affected.
 */
final class LegacyDeliveryDecorator
{
    public int $calls = 0;

    public function __construct(
        private readonly FileDelivery $inner,
    ) {
    }

    public function respond(StoredFile $file, string $variant): Response
    {
        ++$this->calls;

        return $this->inner->respond($file, $variant);
    }
}
