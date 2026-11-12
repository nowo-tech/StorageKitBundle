<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Service\ResetInterface;

use function max;

/**
 * Clears the bundle's per-request memos (profiles, bucket connections, access decisions, pending references) at the
 * request boundary itself (1.4): at the start of every main request, at `kernel.terminate`, after every Messenger
 * message, handled or failed, and (1.5) at the end of every outermost console command. A long-running host command (a loop over many viewers or tenants, a scheduler) has no
 * such boundary: it calls the public {@see self::reset()} of this service per item. A FrankenPHP worker with the kernel kept alive and no services resetter (`FRANKENPHP_RESET_KERNEL=false`)
 * then never answers one request with a decision, a profile or a connection memoised for another user or tenant;
 * `kernel.reset` stays as well.
 */
final class RequestBoundaryListener
{
    /** Console commands running in this process (1.5, audit NH-4: only the outermost one's end is a boundary). */
    private int $consoleDepth = 0;

    /**
     * @param iterable<ResetInterface> $memos the bundle services that memoise per request
     */
    public function __construct(
        private readonly iterable $memos,
    ) {
    }

    /**
     * Start of a console command (`console.command`): one level deeper.
     */
    public function onConsoleCommand(): void
    {
        // @igor-ignore - Console nesting depth, incremented and decremented at the command boundaries.
        ++$this->consoleDepth;
    }

    /**
     * End of a console command (`console.terminate`): the outermost one is a boundary; a command run in-process by
     * another one is not (the outer command's memos stay).
     */
    public function onConsoleTerminate(): void
    {
        // @igor-ignore - Console nesting depth, incremented and decremented at the command boundaries.
        $this->consoleDepth = max(0, $this->consoleDepth - 1);
        if ($this->consoleDepth === 0) {
            $this->reset();
        }
    }

    /**
     * Main request: start from empty memos.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->reset();
        }
    }

    /**
     * After the response (`kernel.terminate`), after a Messenger message (`WorkerMessageHandledEvent`,
     * `WorkerMessageFailedEvent`).
     */
    public function onBoundary(): void
    {
        $this->reset();
    }

    /**
     * Resets every memo. Public API: a long-running host command calls it between items.
     */
    public function reset(): void
    {
        foreach ($this->memos as $memo) {
            $memo->reset();
        }
    }
}
