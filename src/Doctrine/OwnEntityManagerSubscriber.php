<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\StorageKitBundle\EventListener\UnitOfWorkFrames;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use const PHP_INT_MAX;

/**
 * Keeps the bundle's OWN entity manager (`doctrine.entity_manager`, 1.5) usable in a kept-alive kernel (FrankenPHP
 * worker mode, kernel reset false; REQ-RUNTIME-002 rule 4).
 *
 * Registered only when `doctrine.entity_manager` names a manager other than the host default: that manager maps the
 * bundle entities only. At the START of every unit (main request, Messenger message, outermost console command) it is
 * recovered when a failed write closed it and cleared, so nothing a unit left behind — even one whose end boundary
 * never ran (a fatal error, a skipped `kernel.terminate`) — reaches the next one (audit NH-3). At the END of every
 * unit it is cleared again, so no managed row is kept between units.
 *
 * Console commands nest (a command run in-process by another one, audit NH-4): only the OUTERMOST command start and
 * end act, and a main request handled in-process inside a command, a message or another main request does not clear
 * the outer unit. Messenger messages always act (`messenger:consume` is itself a command). The host's managers are
 * never reset nor cleared.
 *
 * State (1.5.1, audit NM / NL2-v): the units of work running in this process ({@see UnitOfWorkFrames}), changed at
 * PHP_INT_MAX before any other listener can throw: one frame per console run (keyed by command and input, removed by
 * its own `console.terminate`; a run whose `console.error` listener threw is dropped at the next unit), a message
 * flag and a main-request counter. Before 1.5.1 a plain depth counter could stay above zero for good after a throwing
 * or propagation-stopping console listener, and the manager was then never cleared between requests again.
 *
 * @internal
 */
final class OwnEntityManagerSubscriber
{
    /**
     * Request / message / command boundaries: [event, method, priority] (symfony/messenger is optional: names).
     *
     * @var list<array{string, string, int}>
     */
    public const LISTENERS = [
        [KernelEvents::REQUEST, 'onRequest', PHP_INT_MAX],
        [KernelEvents::FINISH_REQUEST, 'onFinishRequest', PHP_INT_MAX],
        ['Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent', 'onMessageReceived', PHP_INT_MAX],
        [ConsoleEvents::COMMAND, 'onConsoleCommand', PHP_INT_MAX],
        [ConsoleEvents::ERROR, 'onConsoleError', PHP_INT_MAX],
        [ConsoleEvents::TERMINATE, 'onConsoleCommandEnded', PHP_INT_MAX],
        ['Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent', 'onMessageEnded', PHP_INT_MAX],
        ['Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent', 'onMessageEnded', PHP_INT_MAX],
        // Dispatched by messenger:failed:retry only, when a message is skipped.
        ['Symfony\\Component\\Messenger\\Event\\WorkerMessageSkipEvent', 'onMessageEnded', PHP_INT_MAX],
        ['Symfony\\Component\\Messenger\\Event\\WorkerStoppedEvent', 'onWorkerStopped', PHP_INT_MAX],
        [KernelEvents::TERMINATE, 'onTerminate', -1024],
        [ConsoleEvents::TERMINATE, 'onConsoleTerminate', -1024],
        ['Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent', 'clear', -1024],
        ['Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent', 'clear', -1024],
    ];

    /** The units of work running in this process (process lifetime, bounded by the nesting depth). */
    private readonly UnitOfWorkFrames $units;

    /**
     * @param string $managerName the configured `doctrine.entity_manager`
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly string $managerName,
    ) {
        $this->units = new UnitOfWorkFrames();
    }

    /**
     * Start of a main request (the outermost unit only): recover and clear.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->units->requestStarted()) {
            $this->start();
        }
    }

    /**
     * End of a main request (`kernel.finish_request`, PHP_INT_MAX).
     */
    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->units->requestFinished();
        }
    }

    /**
     * End of a request (nothing else running: not an in-process `HttpKernel::handle()`).
     */
    public function onTerminate(): void
    {
        if ($this->units->isIdle()) {
            $this->clear();
        }
    }

    /**
     * Start of a Messenger message: recover and clear.
     */
    public function onMessageReceived(): void
    {
        $this->units->messageReceived();
        $this->start();
    }

    /**
     * A message was handled, failed or skipped: no message is in flight any more (the clear runs late).
     */
    public function onMessageEnded(): void
    {
        $this->units->messageFinished();
    }

    /**
     * The worker stopped: no message is in flight any more.
     */
    public function onWorkerStopped(): void
    {
        $this->units->workerStopped();
    }

    /**
     * Start of a console command run: the outermost one starts a unit.
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        if ($this->units->commandStarted($event)) {
            $this->start();
        }
    }

    /**
     * A command run failed: its frame is marked (dropped at the next unit when no terminate follows).
     */
    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->units->commandFailed($event);
    }

    /**
     * End of a command run (PHP_INT_MAX, before any listener that could throw or stop the propagation).
     */
    public function onConsoleCommandEnded(ConsoleTerminateEvent $event): void
    {
        $this->units->commandEnded($event);
    }

    /**
     * End of a console command run (late): the run that started as the outermost unit ends it.
     */
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if ($this->units->endsOutermostUnit($event)) {
            $this->clear();
        }
    }

    /**
     * Start of a unit: recover a closed manager, then drop whatever an earlier unit left.
     */
    public function start(): void
    {
        $this->recover();
        $this->clear();
    }

    /**
     * Resets the bundle manager when a failed write closed it (the lazy manager is re-created in place).
     */
    public function recover(): void
    {
        $manager = $this->registry->getManager($this->managerName);
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $this->registry->resetManager($this->managerName);
        }
    }

    /**
     * Detaches every managed bundle entity (end of a request, message or outermost command).
     */
    public function clear(): void
    {
        $manager = $this->registry->getManager($this->managerName);
        if ($manager instanceof EntityManagerInterface && $manager->isOpen()) {
            // @igor-ignore - This IS the request-boundary reset of the bundle's own manager (no state kept).
            $manager->clear();
        }
    }
}
