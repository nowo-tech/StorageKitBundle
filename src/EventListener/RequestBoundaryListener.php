<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\EventListener;

use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Clears the bundle's per-request memos (profiles, bucket connections, access decisions, pending references) at the
 * request boundary itself (1.4): at the start of every main request, at `kernel.terminate`, after every Messenger
 * message, handled or failed, and (1.5) at the end of every outermost console command. A long-running host command (a loop over many viewers or tenants, a scheduler) has no
 * such boundary: it calls the public {@see self::reset()} of this service per item. A FrankenPHP worker with the kernel kept alive and no services resetter (`FRANKENPHP_RESET_KERNEL=false`)
 * then never answers one request with a decision, a profile or a connection memoised for another user or tenant;
 * `kernel.reset` stays as well.
 *
 * Console bookkeeping (1.5.1, audit NM / NL2-v): the units of work running in this process ({@see UnitOfWorkFrames})
 * change at PHP_INT_MAX, before any other listener can throw: one frame per console run (keyed by command and input,
 * removed by its own `console.terminate`; a run whose `console.error` listener threw is dropped at the next unit), a
 * message flag and a main-request counter. Before 1.5.1 a plain depth counter could stay above zero for good after a
 * throwing or propagation-stopping console listener, and no command end was a boundary any more. The outermost run is
 * the one started while nothing else ran (a command run by a controller or a message handler keeps the outer memos).
 */
final class RequestBoundaryListener
{
    /** The units of work running in this process (1.5, audit NH-4: only the outermost command end is a boundary). */
    private readonly UnitOfWorkFrames $units;

    /**
     * @param iterable<ResetInterface> $memos the bundle services that memoise per request
     */
    public function __construct(
        private readonly iterable $memos,
    ) {
        $this->units = new UnitOfWorkFrames();
    }

    /**
     * Start of a console command run (`console.command`, PHP_INT_MAX).
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $this->units->commandStarted($event);
    }

    /**
     * A command run failed (`console.error`, PHP_INT_MAX): its frame is marked.
     */
    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->units->commandFailed($event);
    }

    /**
     * End of a command run (`console.terminate`, PHP_INT_MAX).
     */
    public function onConsoleCommandEnded(ConsoleTerminateEvent $event): void
    {
        $this->units->commandEnded($event);
    }

    /**
     * End of a console command (`console.terminate`, late): the outermost run is a boundary; a command run in-process
     * by another one, a controller or a message handler is not (the outer memos stay).
     */
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->units->endsOutermostUnit($event)) {
            $this->reset();
        }
    }

    /**
     * Main request (PHP_INT_MAX): start from empty memos.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->units->requestStarted();
            $this->reset();
        }
    }

    /**
     * End of a main request (`kernel.finish_request`, PHP_INT_MAX).
     */
    public function onKernelFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->units->requestFinished();
        }
    }

    /**
     * A Messenger message was received (`WorkerMessageReceivedEvent`, PHP_INT_MAX).
     */
    public function onMessageReceived(): void
    {
        $this->units->messageReceived();
    }

    /**
     * A message was handled, failed or skipped (PHP_INT_MAX): no message is in flight any more.
     */
    public function onMessageEnded(): void
    {
        $this->units->messageFinished();
    }

    /**
     * The worker stopped (`WorkerStoppedEvent`, PHP_INT_MAX).
     */
    public function onWorkerStopped(): void
    {
        $this->units->workerStopped();
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
