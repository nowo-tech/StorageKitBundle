<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

use RuntimeException;

/**
 * A download that cannot be served (1.5): no such file, an unknown variant, or the file's bucket is not connectable any
 * more. HTTP adapters answer it with a 404 (no difference between missing and forbidden).
 */
final class FileNotDeliverable extends RuntimeException
{
}
