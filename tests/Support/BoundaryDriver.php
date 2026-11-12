<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use ReflectionClass;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageSkipEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Worker;

use function array_pop;
use function count;

/**
 * Dispatches unit-of-work boundary events (requests, Messenger messages, console runs) through a real event dispatcher
 * to the boundary subscribers under test, in the order Symfony dispatches them.
 */
final class BoundaryDriver
{
    /** @var list<array{Command, InputInterface}> */
    private array $running = [];

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly HttpKernelInterface $kernel,
    ) {
    }

    public function request(int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $this->dispatcher->dispatch(new RequestEvent($this->kernel, new Request(), $type), KernelEvents::REQUEST);
    }

    public function finishRequest(int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $this->dispatcher->dispatch(new FinishRequestEvent($this->kernel, new Request(), $type), KernelEvents::FINISH_REQUEST);
    }

    public function terminate(): void
    {
        $this->dispatcher->dispatch(new TerminateEvent($this->kernel, new Request(), new Response()), KernelEvents::TERMINATE);
    }

    public function received(): void
    {
        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
    }

    public function handled(): void
    {
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));
    }

    public function failed(): void
    {
        $this->dispatcher->dispatch(new WorkerMessageFailedEvent(new Envelope(new stdClass()), 'async', new RuntimeException('handler failed')));
    }

    public function skipped(): void
    {
        $this->dispatcher->dispatch(new WorkerMessageSkipEvent(new Envelope(new stdClass()), 'async'));
    }

    public function workerStopped(): void
    {
        $this->dispatcher->dispatch(new WorkerStoppedEvent((new ReflectionClass(Worker::class))->newInstanceWithoutConstructor()));
    }

    public function commandStarts(?Command $command = null): void
    {
        $command ??= new Command('test:command-' . count($this->running));
        $input           = new ArrayInput([]);
        $this->running[] = [$command, $input];
        $this->dispatcher->dispatch(new ConsoleCommandEvent($command, $input, new NullOutput()), ConsoleEvents::COMMAND);
    }

    /** Terminates the innermost running command (one that never started when none runs). */
    public function commandEnds(): void
    {
        [$command, $input] = array_pop($this->running) ?? [new Command('test:unknown'), new ArrayInput([])];
        $this->dispatcher->dispatch(new ConsoleTerminateEvent($command, $input, new NullOutput(), 0), ConsoleEvents::TERMINATE);
    }

    /** The innermost running command fails and an error listener throws: Symfony dispatches no terminate. */
    public function commandFailsWithoutTerminate(): void
    {
        [$command, $input] = array_pop($this->running) ?? [null, new ArrayInput([])];
        $this->dispatcher->dispatch(new ConsoleErrorEvent($input, new NullOutput(), new RuntimeException('command failed'), $command), ConsoleEvents::ERROR);
    }
}
