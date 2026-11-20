<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * Collects the `E_USER_DEPRECATED` messages `$work` triggers, silenced ones (`@trigger_error`, `trigger_deprecation()`)
 * included, without letting them reach PHPUnit (1.8: the private entity manager notices and the host-flush probe).
 */
final class Deprecations
{
    /**
     * @param callable(): mixed $work
     *
     * @return list<string>
     */
    public static function collect(callable $work): array
    {
        $messages = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test helper: restored in the finally block below)
        set_error_handler(static function (int $type, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $work();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }
}
