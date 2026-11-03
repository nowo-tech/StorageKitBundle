<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use Doctrine\Persistence\AbstractManagerRegistry;
use LogicException;

/**
 * A manager registry that is never asked for a manager (view-model tests without a database).
 */
final class NullRegistry extends AbstractManagerRegistry
{
    public function __construct()
    {
        parent::__construct('null', [], [], 'default', 'default', 'Doctrine\Persistence\Proxy');
    }

    protected function getService(string $name): object
    {
        throw new LogicException('No database in this test.');
    }

    protected function resetService(string $name): void
    {
    }
}
