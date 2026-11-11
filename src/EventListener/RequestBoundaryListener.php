<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Clears the bundle's per-request memos (profiles, bucket connections, access decisions, pending references) at the
 * request boundary itself (1.4): at the start of every main request, at `kernel.terminate` and after every Messenger
 * message, handled or failed. A long-running host command (a loop over many viewers or tenants, a scheduler) has no
 * such boundary: it calls the public {@see self::reset()} of this service per item. A FrankenPHP worker with the kernel kept alive and no services resetter (`FRANKENPHP_RESET_KERNEL=false`)
 * then never answers one request with a decision, a profile or a connection memoised for another user or tenant;
 * `kernel.reset` stays as well.
 */
final readonly class RequestBoundaryListener
{
    /**
     * @param iterable<ResetInterface> $memos the bundle services that memoise per request
     */
    public function __construct(
        private iterable $memos,
    ) {
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
     * After the response (`kernel.terminate`) and after a Messenger message (`WorkerMessageHandledEvent`,
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
