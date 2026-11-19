<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use Closure;

use function str_contains;
use function str_starts_with;

/**
 * The hook {@see StatementHook} calls with the SQL of every prepared statement (a public test service: DoctrineBundle
 * clones the middleware per connection, this object is shared by the clones).
 */
final class StatementHooks
{
    /** @var (Closure(string): void)|null */
    public ?Closure $hook = null;

    /**
     * Runs `$action` once, just before the `$nth` prepared statement that contains `$needle`.
     */
    public function onceBeforeNth(string $needle, int $nth, Closure $action): void
    {
        $seen       = 0;
        $this->hook = function (string $sql) use ($needle, $nth, $action, &$seen): void {
            if (str_contains($sql, $needle) && ++$seen === $nth) {
                $this->hook = null;
                $action();
            }
        };
    }

    /**
     * Runs `$action` once, just before the first prepared statement that starts with `$prefix`.
     */
    public function onceBefore(string $prefix, Closure $action): void
    {
        $this->hook = function (string $sql) use ($prefix, $action): void {
            if (str_starts_with($sql, $prefix)) {
                $this->hook = null;
                $action();
            }
        };
    }
}
