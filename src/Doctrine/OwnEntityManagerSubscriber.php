<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function max;

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
 * end act, and a main request handled in-process inside a command does not clear the command's unit. Messenger
 * messages always act (`messenger:consume` is itself a command). The host's managers are never reset nor cleared.
 *
 * State: the console depth (process lifetime, bounded by the nesting depth).
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
        [KernelEvents::REQUEST, 'onRequest', 4096],
        ['Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent', 'start', 4096],
        [ConsoleEvents::COMMAND, 'onConsoleCommand', 4096],
        [KernelEvents::TERMINATE, 'onTerminate', -1024],
        [ConsoleEvents::TERMINATE, 'onConsoleTerminate', -1024],
        ['Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent', 'clear', -1024],
        ['Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent', 'clear', -1024],
    ];

    /** Console commands running in this process (nested in-process runs count). */
    private int $consoleDepth = 0;

    /**
     * @param string $managerName the configured `doctrine.entity_manager`
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly string $managerName,
    ) {
    }

    /**
     * Start of a main request (outside a console command): recover and clear.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->consoleDepth === 0) {
            $this->start();
        }
    }

    /**
     * End of a request (outside a console command).
     */
    public function onTerminate(): void
    {
        if ($this->consoleDepth === 0) {
            $this->clear();
        }
    }

    /**
     * Start of a console command: the outermost one starts a unit.
     */
    public function onConsoleCommand(): void
    {
        // @igor-ignore - Console nesting depth, incremented and decremented at the command boundaries.
        ++$this->consoleDepth;
        if ($this->consoleDepth === 1) {
            $this->start();
        }
    }

    /**
     * End of a console command: the outermost one ends the unit.
     */
    public function onConsoleTerminate(): void
    {
        // @igor-ignore - Console nesting depth, incremented and decremented at the command boundaries.
        $this->consoleDepth = max(0, $this->consoleDepth - 1);
        if ($this->consoleDepth === 0) {
            $this->clear();
        }
    }

    /**
     * Start of a unit (also a Messenger message): recover a closed manager, then drop whatever an earlier unit left.
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
