<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures\Ports;

/**
 * A service that implements neither download API: aliasing the port to it must fail the container build.
 */
final class NotADelivery
{
}
