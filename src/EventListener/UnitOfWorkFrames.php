<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\EventListener;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use WeakMap;
use WeakReference;

use function array_key_last;
use function array_pop;
use function array_splice;
use function count;

/**
 * Unit-of-work bookkeeping of a boundary listener (1.5.1, audit NM / NL2-v): which units run in this process,
 * the listener acts only at the OUTERMOST boundary of a request, a Messenger message or a console command. Audit NX CR1–CR3: per-worker message state and a stack of main requests.
 *
 * A plain `++` on `console.command` / `--` on a late `console.terminate` leaked in a long-lived process: a terminate
 * listener that threw or stopped the propagation before the decrement, or a `console.error` listener that threw
 * (Symfony then skips `console.terminate`), left the depth above zero forever, so every later boundary gated on "no
 * command running" stopped clearing. Here, with every change made at PHP_INT_MAX:
 * - each console run is a FRAME on a stack, keyed by its Command object AND its input (a command that runs itself
 *   in-process has another input object per run). A frame saves the worker and request stacks when it is pushed and
 *   restores them when it is removed, so a message or a request that never ended inside a command does not outlive
 *   it; the end of a main request or a message removes the frames started inside it. A frame is the outermost unit
 *   when nothing ran when it was pushed;
 * - `console.terminate` removes ITS frame and the frames above it (nested runs that never terminated); a terminate
 *   without a frame removes nothing (never a blind decrement);
 * - `console.error` marks the frame as failing. The mark stays until the frame's own terminate removes it: when an
 *   error listener throws, Symfony never dispatches that terminate, and a still-marked frame is dropped (its saved
 *   state restored) at the next main request, Messenger event or command;
 * - main requests: a STACK of the main `Request` objects in flight (audit NX CR2). A main request is the outermost unit
 *   only when no other main request is in flight; `kernel.finish_request` / `kernel.terminate` pop their request and
 *   the requests above it (nested requests whose end never came), an unknown request pops nothing. A main request that
 *   is the HTTP kernel's own main request (its `RequestStack`, popped in a `finally`) drops the requests left on the
 *   stack by a request whose end never came (a throwing `kernel.exception` listener). `kernel.terminate` ends the
 *   outermost unit only when nothing runs any more (the terminate of a nested main request keeps the outer one, audit
 *   OB-L3);
 * - Messenger: a STACK of the running workers (`WorkerStartedEvent` → `WorkerStoppedEvent`), each with its message in
 *   flight (audit NX CR1). A worker run in-process inside a message handler gets its own entry, so its events never end
 *   the outer message. A message ends at handled / failed / `WorkerMessageSkipEvent` (dispatched only by
 *   `messenger:failed:retry`), at its worker's next `WorkerRunningEvent` (also dispatched after a message a listener
 *   skipped with `shouldHandle(false)`, audit NX CR3) or its worker's stop; a received event whose final
 *   `shouldHandle()` is false (read at PHP_INT_MIN) ends it too. A message received outside any worker the tracker saw
 *   start gets an entry without a worker. A message is the outermost unit when no other message is in flight. A run
 *   that aborted (an exception thrown out of `Worker::run()`: no handled, running or stopped event) is dropped as soon
 *   as its worker or its in-flight received event is gone (weak references, audits OB-M1 / OB-L1).
 *
 * The verdict of each boundary event ("did this unit start / end as the outermost one?") is kept per event object
 * (`WeakMap`), so the late listeners read the value computed when the event started, whatever ran in between.
 *
 * Every handler first drops the failing frames (restoring the state they saved) and only then writes its own state,
 * so a restored frame never overwrites the state the handler just wrote (audit NL2-v2).
 *
 * Process-lifetime state, bounded by the nesting depth; it holds no request data beyond the requests in flight and is
 * deliberately not reset by `kernel.reset` (it describes the units still running).
 *
 * @internal
 */
final class UnitOfWorkFrames
{
    /** @var list<array{command: Command, input: InputInterface, outermost: bool, failing: bool, workers: list<array{worker: WeakReference<object>|null, message: bool, event: WeakReference<object>|null}>, requests: list<Request>}> */
    private array $frames = [];

    /**
     * Running Messenger workers and their message in flight, outermost first. The worker and the received event are
     * weak references: `Worker::handleMessage()` keeps its received event alive until it returns, so a dead event with
     * the message still in flight (or a dead worker) means the run aborted (audit OB-M1).
     *
     * @var list<array{worker: WeakReference<object>|null, message: bool, event: WeakReference<object>|null}>
     */
    private array $workers = [];

    /** @var list<Request> main requests in flight (between their `kernel.request` and `kernel.finish_request`), outermost first */
    private array $requests = [];

    /** @var WeakMap<object, bool> outermost verdict per boundary event */
    private WeakMap $verdicts;

    /**
     * @param RequestStack|null $requestStack the HTTP kernel's request stack (read only), to tell a nested main request
     *                                        from one left on the stack by a request whose end never came
     */
    public function __construct(
        private readonly ?RequestStack $requestStack = null,
    ) {
        $this->verdicts = new WeakMap();
    }

    /**
     * Main `kernel.request`: whether it starts the outermost unit (nothing else runs, not even another main request).
     */
    public function requestStarted(Request $request): bool
    {
        $this->dropStaleUnits();
        if ($this->requests !== [] && $this->requestStack?->getMainRequest() === $request) {
            // The HTTP kernel runs no other request (it pops its own stack in a `finally`): the requests left here never
            // reached their finish_request / terminate (a throwing `kernel.exception` listener).
            // @igor-ignore - Stack of the main requests in flight (no request data kept), healed at a top-level start.
            $this->requests = [];
        }

        $outermost = $this->isIdle();
        // @igor-ignore - Stack of the main requests in flight, popped at their own finish_request / terminate.
        $this->requests[] = $request;

        return $outermost;
    }

    /**
     * Main `kernel.finish_request`.
     */
    public function requestFinished(Request $request): void
    {
        $this->dropStaleUnits();
        $this->endRequest($request);
    }

    /**
     * `kernel.terminate` (PHP_INT_MAX): pops the request (and the requests above it whose end never came). It ends the
     * outermost unit only when nothing runs any more, so the in-process terminate of a nested main request keeps the
     * outer one (audit OB-L3). Requests left below by a request whose end never came are dropped at the HTTP kernel's
     * next main request ({@see requestStarted()}).
     */
    public function requestBoundaryEnded(TerminateEvent $event): void
    {
        $this->dropStaleUnits();
        $this->endRequest($event->getRequest());
        // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
        $this->verdicts[$event] = $this->isIdle();
    }

    /**
     * Whether nothing runs.
     */
    public function isIdle(): bool
    {
        return $this->frames === [] && !$this->isMessageInFlight() && $this->requests === [];
    }

    /**
     * Whether a Messenger message is in flight in any worker.
     */
    public function isMessageInFlight(): bool
    {
        $this->dropAbortedRuns();

        return $this->messageDepth($this->workers) > 0;
    }

    /**
     * Number of main requests in flight.
     */
    public function requestsInFlight(): int
    {
        return count($this->requests);
    }

    /**
     * Number of Messenger workers (and messages received outside a known worker) the bookkeeping holds.
     */
    public function workerEntries(): int
    {
        $this->dropAbortedRuns();

        return count($this->workers);
    }

    /**
     * `WorkerStartedEvent`: the worker's own entry, so a worker run inside a message handler never ends the outer message.
     */
    public function workerStarted(object $event): void
    {
        $this->dropStaleUnits();
        if (!$event instanceof WorkerStartedEvent) {
            return;
        }

        $worker = $event->getWorker();
        $index  = $this->indexOfWorker($worker);
        if ($index !== null) {
            // A previous run of the same worker whose stop never came.
            // @igor-ignore - Stack of the running workers (no message data).
            array_splice($this->workers, $index);
        }

        // @igor-ignore - Stack of the running workers (no message data), popped by their own stop.
        $this->workers[] = ['worker' => WeakReference::create($worker), 'message' => false, 'event' => null];
    }

    /**
     * `WorkerMessageReceivedEvent` (PHP_INT_MAX): whether the message is the outermost unit (no other message in flight).
     */
    public function messageReceived(object $event): bool
    {
        $this->dropStaleUnits();
        $outermost = $this->messageDepth($this->workers) === 0;
        // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
        $this->verdicts[$event] = $outermost;
        $received               = $event instanceof WorkerMessageReceivedEvent ? WeakReference::create($event) : null;
        $top                    = array_key_last($this->workers);
        if ($top === null || $this->workers[$top]['message']) {
            // @igor-ignore - A message received outside any worker the tracker saw start (no message data).
            $this->workers[] = ['worker' => null, 'message' => true, 'event' => $received];
        } else {
            // @igor-ignore - Unit-of-work flag of the worker, set and unset at the Messenger boundaries.
            $this->workers[$top] = ['worker' => $this->workers[$top]['worker'], 'message' => true, 'event' => $received];
        }

        return $outermost;
    }

    /**
     * `WorkerMessageReceivedEvent` (PHP_INT_MIN): a listener that called `shouldHandle(false)` skipped the message, which
     * the Worker then never reports as handled or failed (audit NX CR3). Reads the final decision; marks nothing. Only
     * THIS event's message ends, and only while it is still in flight: a listener that already ended it (a
     * `WorkerMessageSkipEvent` dispatched by `messenger:failed:retry`) never makes it end an outer message (audit OB-L2).
     */
    public function messageReceivedDecided(object $event): void
    {
        if (!$event instanceof WorkerMessageReceivedEvent || $event->shouldHandle()) {
            return;
        }

        $this->dropStaleUnits();
        for ($index = count($this->workers) - 1; $index >= 0; --$index) {
            if ($this->workers[$index]['message'] && $this->workers[$index]['event']?->get() === $event) {
                $this->endMessageAt($index);
                $this->dropFramesStartedInsideAMessage();

                return;
            }
        }
    }

    /**
     * Handled, failed or skipped message: the verdict for its late listeners is "no other message still in flight".
     */
    public function messageFinished(object $event): void
    {
        $this->dropStaleUnits();
        $this->endMessage();
        // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
        $this->verdicts[$event] = !$this->isMessageInFlight();
    }

    /**
     * `WorkerRunningEvent`: dispatched by the Worker after every message (handled, failed or skipped) and when idle; no
     * message of that worker is in flight, and the workers started above it are gone.
     */
    public function workerRunning(object $event): void
    {
        $this->dropStaleUnits();
        $index = $this->indexOfWorker($event instanceof WorkerRunningEvent ? $event->getWorker() : null);
        if ($index === null) {
            $this->popWorkerlessMessages();

            return;
        }

        $this->endMessageAt($index);
        $this->dropFramesStartedInsideAMessage();
    }

    /**
     * `WorkerStoppedEvent`: the worker's entry and the workers started above it end.
     */
    public function workerStopped(object $event): void
    {
        $this->dropStaleUnits();
        $index = $this->indexOfWorker($event instanceof WorkerStoppedEvent ? $event->getWorker() : null);
        if ($index === null) {
            $this->popWorkerlessMessages();
        } else {
            // @igor-ignore - Stack of the running workers (no message data).
            array_splice($this->workers, $index);
        }

        $this->dropFramesStartedInsideAMessage();
    }

    /**
     * `console.command`: pushes the run's frame; returns whether it is the outermost unit.
     */
    public function commandStarted(ConsoleCommandEvent $event): bool
    {
        $this->dropStaleUnits();
        $outermost = $this->isIdle();
        $command   = $event->getCommand();
        if ($command instanceof Command) {
            // @igor-ignore - Frames of the running commands (no request data), removed by their own terminate.
            $this->frames[] = ['command' => $command, 'input' => $event->getInput(), 'outermost' => $outermost, 'failing' => false, 'workers' => $this->workers, 'requests' => $this->requests];
        }

        return $outermost;
    }

    /**
     * `console.error`: marks the run's frame as failing (an error without a frame changes nothing).
     */
    public function commandFailed(ConsoleErrorEvent $event): void
    {
        $index = $this->indexOf($event->getCommand(), $event->getInput());
        if ($index !== null) {
            // @igor-ignore - Failure marker of a running command, removed with its frame.
            $this->frames[$index]['failing'] = true;
        }
    }

    /**
     * `console.terminate`: removes the run's frame and the frames above it; a run without a frame changes nothing.
     */
    public function commandEnded(ConsoleTerminateEvent $event): void
    {
        $index = $this->indexOf($event->getCommand(), $event->getInput());
        if ($index !== null) {
            // @igor-ignore - Per-event verdict; the WeakMap entry dies with the event.
            $this->verdicts[$event] = $this->frames[$index]['outermost'];
            $this->removeFrames($index);
        }
    }

    /**
     * Whether the unit that $event ends (a command run, a request, a message) was the outermost one; false for an event
     * the bookkeeping never saw.
     */
    public function endsOutermostUnit(object $event): bool
    {
        return $this->verdicts[$event] ?? false;
    }

    private function indexOf(?Command $command, InputInterface $input): ?int
    {
        for ($index = count($this->frames) - 1; $index >= 0; --$index) {
            if ($this->frames[$index]['command'] === $command && $this->frames[$index]['input'] === $input) {
                return $index;
            }
        }

        return null;
    }

    private function indexOfWorker(?object $worker): ?int
    {
        if ($worker === null) {
            return null;
        }

        for ($index = count($this->workers) - 1; $index >= 0; --$index) {
            if ($this->workers[$index]['worker']?->get() === $worker) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array{worker: WeakReference<object>|null, message: bool, event: WeakReference<object>|null}> $workers
     */
    private function messageDepth(array $workers): int
    {
        $depth = 0;
        foreach ($workers as $worker) {
            if ($worker['message']) {
                ++$depth;
            }
        }

        return $depth;
    }

    /**
     * Pops $request and the requests above it (nested requests whose end never came), then the runs started inside
     * them that never terminated; an unknown request pops nothing.
     */
    private function endRequest(Request $request): void
    {
        for ($index = count($this->requests) - 1; $index >= 0; --$index) {
            if ($this->requests[$index] === $request) {
                // @igor-ignore - Stack of the main requests in flight (no request data kept).
                array_splice($this->requests, $index);
                break;
            }
        }

        foreach ($this->frames as $index => $frame) {
            if (count($frame['requests']) > count($this->requests)) {
                // @igor-ignore - Frames of the running commands (no request data), ended with their request.
                array_splice($this->frames, $index);

                return;
            }
        }
    }

    /**
     * Ends the innermost message in flight (the workers started above it, never stopped, are gone), then the runs
     * started inside it that never terminated.
     */
    private function endMessage(): void
    {
        for ($index = count($this->workers) - 1; $index >= 0; --$index) {
            if ($this->workers[$index]['message']) {
                $this->endMessageAt($index);

                break;
            }
        }

        $this->dropFramesStartedInsideAMessage();
    }

    /**
     * Ends the message of the entry at $index: the entries above it are gone, an entry without a worker is removed, a
     * worker keeps running without a message.
     */
    private function endMessageAt(int $index): void
    {
        $worker = $this->workers[$index]['worker'];
        // @igor-ignore - Stack of the running workers (no message data).
        array_splice($this->workers, $index);
        if ($worker !== null) {
            // @igor-ignore - Stack of the running workers (no message data): the worker keeps running without a message.
            $this->workers[] = ['worker' => $worker, 'message' => false, 'event' => null];
        }
    }

    /**
     * Drops the runs that aborted (audit OB-M1): an exception thrown out of `Worker::run()` (a throwing received,
     * handled or running listener, a transport failure) leaves no handled, running or stopped event. Such a run is over
     * once its worker is gone or, with a message in flight, once the received event of that message is gone (the Worker
     * keeps it alive until `handleMessage()` returns). The entries above it ran inside it and are dropped with it, so the
     * stack never grows with aborted runs (audit OB-L1).
     */
    private function dropAbortedRuns(): void
    {
        foreach ($this->workers as $index => $entry) {
            if ($entry['worker'] === null) {
                continue;
            }

            if ($entry['worker']->get() === null || ($entry['message'] && $entry['event'] !== null && $entry['event']->get() === null)) {
                // @igor-ignore - Stack of the running workers (no message data).
                array_splice($this->workers, $index);
                $this->dropFramesStartedInsideAMessage();

                return;
            }
        }
    }

    /**
     * Every handler first drops the failing command frames (restoring the state they saved), then the aborted worker
     * runs, and only then writes its own state.
     */
    private function dropStaleUnits(): void
    {
        $this->dropFailingFrames();
        $this->dropAbortedRuns();
    }

    /**
     * A running / stopped event of a worker the bookkeeping never saw start: the messages received outside any known
     * worker are over; another worker's entry is never touched.
     */
    private function popWorkerlessMessages(): void
    {
        while ($this->workers !== [] && $this->workers[array_key_last($this->workers)]['worker'] === null) {
            // @igor-ignore - Stack of the running workers (no message data).
            array_pop($this->workers);
        }
    }

    /**
     * Runs started inside a message that ended without their terminate end with it.
     */
    private function dropFramesStartedInsideAMessage(): void
    {
        $depth = $this->messageDepth($this->workers);
        foreach ($this->frames as $index => $frame) {
            if ($this->messageDepth($frame['workers']) > $depth) {
                // @igor-ignore - Frames of the running commands (no request data), ended with their message.
                array_splice($this->frames, $index);

                return;
            }
        }
    }

    /**
     * Drops the outermost frame whose error listeners threw (Symfony never dispatches its terminate), with the frames
     * above it.
     */
    private function dropFailingFrames(): void
    {
        foreach ($this->frames as $index => $frame) {
            if ($frame['failing']) {
                $this->removeFrames($index);

                return;
            }
        }
    }

    /**
     * Removes the frame at $index and the frames above it, restoring the state saved when that frame was pushed.
     */
    private function removeFrames(int $index): void
    {
        // @igor-ignore - Running workers restored from the removed frame.
        $this->workers = $this->frames[$index]['workers'];
        // @igor-ignore - Main requests in flight, restored from the removed frame.
        $this->requests = $this->frames[$index]['requests'];
        // @igor-ignore - Frames of the running commands (no request data), removed by their own terminate or self-heal.
        array_splice($this->frames, $index);
    }
}
