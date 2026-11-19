<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use Closure;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Statement;

/**
 * DBAL middleware of the concurrency tests: calls {@see StatementHooks::$hook} with the SQL of every prepared statement (every
 * statement with parameters) before it runs.
 * The hook may throw (an injected deadlock) or write through another connection (a deterministic interleaving of two
 * writers, REQ-RUNTIME-003). Register it as a `doctrine.middleware` service; same API on DBAL 3 and 4.
 */
final readonly class StatementHook implements Middleware
{
    public function __construct(
        private StatementHooks $hooks,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        $hooks = $this->hooks;

        return new class($driver, $hooks) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly StatementHooks $hooks)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): DriverConnection
            {
                return new class(parent::connect($params), $this->hooks) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly StatementHooks $hooks)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->call($sql);

                        return parent::prepare($sql);
                    }

                    private function call(string $sql): void
                    {
                        $hook = $this->hooks->hook;
                        if ($hook instanceof Closure) {
                            $hook($sql);
                        }
                    }
                };
            }
        };
    }
}
