<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

use function assert;

/**
 * Something the request commits before the bundle's unit runs (a host listener's write): when armed, a no-op write
 * outside any transaction on the default connection at `kernel.controller`, which the commit-tracking middleware counts
 * as a commit.
 */
final class EarlyCommit
{
    public bool $armed = false;

    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    public function __invoke(): void
    {
        if ($this->armed) {
            $this->armed = false;
            $connection  = $this->doctrine->getConnection();
            assert($connection instanceof Connection);
            $connection->executeStatement('DELETE FROM storage_kit_file_reference WHERE 1 = 0');
        }
    }
}
