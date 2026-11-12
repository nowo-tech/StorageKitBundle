<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\EventListener;

use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Nowo\StorageKitBundle\EventListener\RequestBoundaryListener;
use Nowo\StorageKitBundle\EventListener\UnitOfWorkFrames;
use Nowo\StorageKitBundle\Tests\Support\BoundaryDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Service\ResetInterface;

use const PHP_INT_MAX;

#[CoversClass(RequestBoundaryListener::class)]
#[CoversClass(UnitOfWorkFrames::class)]
final class RequestBoundaryListenerTest extends TestCase
{
    private int $resets = 0;

    public function testResetsOnMainRequestsAndBoundariesOnly(): void
    {
        $units = $this->driver();

        $units->request(HttpKernelInterface::SUB_REQUEST);
        self::assertSame(0, $this->resets, 'a sub-request keeps the memos of its main request');
        $units->request();
        self::assertSame(1, $this->resets);
        $units->finishRequest(HttpKernelInterface::SUB_REQUEST);
        $units->finishRequest();
        $units->finishRequest();
        $units->terminate();
        self::assertSame(2, $this->resets);
        $units->received();
        $units->handled();
        $units->received();
        $units->failed();
        $units->received();
        $units->skipped();
        $units->workerStopped();
        self::assertSame(4, $this->resets, 'handled and failed messages are boundaries');
    }

    public function testOnlyTheOutermostConsoleCommandEndIsABoundary(): void
    {
        $units = $this->driver();

        $units->commandStarts();
        $units->commandStarts();
        $units->commandEnds();
        self::assertSame(0, $this->resets, 'a command run in-process by another one keeps the outer memos');
        $units->commandEnds();
        self::assertSame(1, $this->resets);
        $units->commandEnds();
        self::assertSame(1, $this->resets, 'a run that never started ends nothing (audit NM)');

        // A command run by a controller or a message handler keeps the outer memos.
        $units->request();
        $units->commandStarts();
        $units->commandEnds();
        $units->finishRequest();
        $units->received();
        $units->commandStarts();
        $units->commandEnds();
        $units->handled();
        self::assertSame(3, $this->resets);
    }

    /**
     * Audit NM / NL2-v: same command run in itself, failing runs without terminate.
     */
    public function testFramesSurviveRecursionAndFailingRuns(): void
    {
        $units   = $this->driver();
        $command = new Command('app:recursive');

        $units->commandStarts($command);
        $units->commandStarts($command);
        $units->commandEnds();
        self::assertSame(0, $this->resets);
        $units->commandEnds();
        self::assertSame(1, $this->resets);

        // An error listener threw: no terminate. The next command is the outermost one again.
        $units->commandStarts();
        $units->commandFailsWithoutTerminate();
        $units->commandFailsWithoutTerminate();
        $units->commandStarts();
        $units->commandEnds();
        self::assertSame(2, $this->resets);

        // Inside a request: the run ends with the request.
        $units->request();
        $units->commandStarts();
        $units->commandFailsWithoutTerminate();
        $units->finishRequest();
        $units->commandStarts();
        $units->commandEnds();
        self::assertSame(4, $this->resets);

        // Inside a message, without a terminate (and without a failure mark): the run ends with the message.
        $units->received();
        $units->commandStarts();
        $units->handled();
        $units->commandStarts();
        $units->commandEnds();
        self::assertSame(6, $this->resets);
    }

    public function testThePublicResetAndBoundaryResetEveryMemo(): void
    {
        $listener = new RequestBoundaryListener([$this->memo()]);
        $listener->onBoundary();
        $listener->reset();
        self::assertSame(2, $this->resets);
    }

    /**
     * Audit NX: the worker events keep each worker's own message state; a skipped message ends, so the next command is
     * the outermost unit again.
     */
    public function testWorkerEventsTrackTheMessageOfEachWorker(): void
    {
        $listener = new RequestBoundaryListener([$this->memo()]);
        $worker   = (new ReflectionClass(Worker::class))->newInstanceWithoutConstructor();
        $received = new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async');

        $listener->onWorkerStarted(new WorkerStartedEvent($worker));
        $listener->onMessageReceived($received);
        $received->shouldHandle(false);
        $listener->onMessageReceivedDecided($received);
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, true));
        $listener->onWorkerStopped(new WorkerStoppedEvent($worker));
        $after = new Command('app:after');
        $input = new ArrayInput([]);
        $listener->onConsoleCommand(new ConsoleCommandEvent($after, $input, new NullOutput()));
        $terminate = new ConsoleTerminateEvent($after, $input, new NullOutput(), 0);
        $listener->onConsoleCommandEnded($terminate);
        $listener->onConsoleTerminate($terminate);
        self::assertSame(1, $this->resets, 'the command after the skipped message is the outermost unit');
    }

    private function driver(): BoundaryDriver
    {
        $listener   = new RequestBoundaryListener([$this->memo()]);
        $dispatcher = new EventDispatcher();
        foreach ([
            [KernelEvents::REQUEST, 'onKernelRequest', PHP_INT_MAX],
            [KernelEvents::FINISH_REQUEST, 'onKernelFinishRequest', PHP_INT_MAX],
            [KernelEvents::TERMINATE, 'onKernelTerminateStarted', PHP_INT_MAX],
            [KernelEvents::TERMINATE, 'onBoundary', 0],
            [NowoStorageKitExtension::WORKER_MESSAGE_RECEIVED, 'onMessageReceived', PHP_INT_MAX],
            [NowoStorageKitExtension::WORKER_MESSAGE_HANDLED, 'onMessageEnded', PHP_INT_MAX],
            [NowoStorageKitExtension::WORKER_MESSAGE_FAILED, 'onMessageEnded', PHP_INT_MAX],
            [NowoStorageKitExtension::WORKER_MESSAGE_SKIPPED, 'onMessageEnded', PHP_INT_MAX],
            [NowoStorageKitExtension::WORKER_STOPPED, 'onWorkerStopped', PHP_INT_MAX],
            [NowoStorageKitExtension::WORKER_MESSAGE_HANDLED, 'onBoundary', 0],
            [NowoStorageKitExtension::WORKER_MESSAGE_FAILED, 'onBoundary', 0],
            [ConsoleEvents::COMMAND, 'onConsoleCommand', PHP_INT_MAX],
            [ConsoleEvents::ERROR, 'onConsoleError', PHP_INT_MAX],
            [ConsoleEvents::TERMINATE, 'onConsoleCommandEnded', PHP_INT_MAX],
            [ConsoleEvents::TERMINATE, 'onConsoleTerminate', 0],
        ] as [$event, $method, $priority]) {
            $dispatcher->addListener($event, [$listener, $method], $priority);
        }

        return new BoundaryDriver($dispatcher, $this->createStub(HttpKernelInterface::class));
    }

    private function memo(): ResetInterface
    {
        $this->resets = 0;
        $memo         = $this->createStub(ResetInterface::class);
        $memo->method('reset')->willReturnCallback(function (): void {
            ++$this->resets;
        });

        return $memo;
    }
}
