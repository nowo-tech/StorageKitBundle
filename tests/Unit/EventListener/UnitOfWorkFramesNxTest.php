<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\EventListener;

use Nowo\StorageKitBundle\EventListener\UnitOfWorkFrames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Worker;

/**
 * Audit NX CR1–CR3: the per-worker message state and the main-request stack of {@see UnitOfWorkFrames}, event by
 * event (the kernel-level scenarios run in the worker integration tests).
 */
#[CoversClass(UnitOfWorkFrames::class)]
final class UnitOfWorkFramesNxTest extends TestCase
{
    public function testWorkerEntriesSurviveLostStopEvents(): void
    {
        $units  = new UnitOfWorkFrames();
        $outer  = $this->worker();
        $nested = $this->worker();

        $units->workerStarted(new stdClass());
        $units->workerStarted(new WorkerStartedEvent($outer));
        $units->workerStarted(new WorkerStartedEvent($outer));
        self::assertTrue($units->messageReceived($this->received()));
        $units->workerStarted(new WorkerStartedEvent($nested));
        self::assertFalse($units->messageReceived($inner = $this->received()), 'a message inside a message is nested');
        $units->messageFinished($inner);
        self::assertFalse($units->endsOutermostUnit($inner));
        self::assertTrue($units->isMessageInFlight(), 'the inner message ended, the outer one runs');

        // The nested worker never stopped: the outer message's end drops it.
        $handled = new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async');
        $units->messageFinished($handled);
        self::assertTrue($units->endsOutermostUnit($handled));
        self::assertFalse($units->isMessageInFlight());

        // One entry per worker: its stop ends everything it ran.
        $units->messageReceived($this->received());
        $units->workerStopped(new WorkerStoppedEvent($outer));
        self::assertFalse($units->isMessageInFlight());
    }

    public function testRunningEventsOfUnknownWorkersEndOnlyMessagesWithoutAWorker(): void
    {
        $units = new UnitOfWorkFrames();
        $known = $this->worker();

        $units->messageReceived($this->received());
        $units->messageReceived($this->received());
        $units->workerRunning(new WorkerRunningEvent($this->worker(), false));
        self::assertFalse($units->isMessageInFlight(), 'messages received outside any worker end');

        $units->workerStarted(new WorkerStartedEvent($known));
        $units->messageReceived($this->received());
        $units->workerRunning(new stdClass());
        $units->workerStopped(new WorkerStoppedEvent($this->worker()));
        self::assertTrue($units->isMessageInFlight(), 'another worker\'s events never end this one\'s message');
        $units->workerRunning(new WorkerRunningEvent($known, false));
        self::assertFalse($units->isMessageInFlight());
    }

    public function testASkippedReceivedEventEndsItsMessage(): void
    {
        $units    = new UnitOfWorkFrames();
        $received = $this->received();

        $units->messageReceived($received);
        $units->messageReceivedDecided($received);
        $units->messageReceivedDecided(new stdClass());
        self::assertTrue($units->isMessageInFlight(), 'a handled message is still in flight');
        $received->shouldHandle(false);
        $units->messageReceivedDecided($received);
        self::assertFalse($units->isMessageInFlight());
    }

    public function testMainRequestsAreAStack(): void
    {
        $stack = new RequestStack();
        $units = new UnitOfWorkFrames($stack);
        $outer = Request::create('/outer');
        $stack->push($outer);

        self::assertTrue($units->requestStarted($outer));
        self::assertFalse($units->requestStarted(Request::create('/nested')), 'a nested main request is not outermost');
        $units->requestFinished(Request::create('/unknown'));
        self::assertSame(2, $units->requestsInFlight(), 'an unknown request pops nothing');
        $units->requestFinished($outer);
        self::assertSame(0, $units->requestsInFlight(), 'the outer request pops the nested one whose end never came');

        // A top-level request whose end never came: the next one is the HTTP kernel's main request.
        self::assertTrue($units->requestStarted($outer));
        $stack->pop();
        $next = Request::create('/next');
        $stack->push($next);
        self::assertTrue($units->requestStarted($next));
        self::assertSame(1, $units->requestsInFlight());
    }

    private function received(): WorkerMessageReceivedEvent
    {
        return new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async');
    }

    private function worker(): Worker
    {
        return (new ReflectionClass(Worker::class))->newInstanceWithoutConstructor();
    }
}
