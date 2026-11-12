<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\EventListener;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use WeakMap;

use function array_splice;
use function count;

/**
 * Unit-of-work bookkeeping of a boundary listener (1.5.1, audit NM / NL2-v): which units run in this process,
 * the listener acts only at the OUTERMOST boundary of a request, a Messenger message or a console command.
 *
 * A plain `++` on `console.command` / `--` on a late `console.terminate` leaked in a long-lived process: a terminate
 * listener that threw or stopped the propagation before the decrement, or a `console.error` listener that threw
 * (Symfony then skips `console.terminate`), left the depth above zero forever, so every later boundary gated on "no
 * command running" stopped clearing. Here, with every change made at PHP_INT_MAX:
 * - each console run is a FRAME on a stack, keyed by its Command object AND its input (a command that runs itself
 *   in-process has another input object per run). A frame saves the message flag and the request depth when it is
 *   pushed and restores them when it is removed, so a message or a request that never ended inside a command does not
 *   outlive it; the end of a main request or a message removes the frames started inside it. A frame is the outermost
 *   unit when nothing ran when it was pushed;
 * - `console.terminate` removes ITS frame and the frames above it (nested runs that never terminated); a terminate
 *   without a frame removes nothing (never a blind decrement);
 * - `console.error` marks the frame as failing. The mark stays until the frame's own terminate removes it: when an
 *   error listener throws, Symfony never dispatches that terminate, and a still-marked frame is dropped (its saved
 *   state restored) at the next main request, received message, command or worker stop;
 * - main requests are counted (a main request handled in-process inside another one does not end it early) and a
 *   Messenger message in flight is a flag (received → handled / failed / skipped / worker stopped).
 *
 * The console verdict ("did this run start as the outermost unit?") is kept per event object (`WeakMap`), so the
 * listener's late methods read the value computed when the run started, whatever ran in between.
 *
 * Every handler first drops the failing frames (restoring the state they saved) and only then writes its own flag or
 * counter, so a restored frame never overwrites the state the handler just wrote (audit NL2-v2).
 *
 * Process-lifetime state, bounded by the nesting depth; it holds no request data and is deliberately not reset by
 * `kernel.reset` (it describes the units still running).
 *
 * @internal
 */
final class UnitOfWorkFrames
{
    /** @var list<array{command: Command, input: InputInterface, outermost: bool, failing: bool, message: bool, requests: int}> */
    private array $frames = [];

    private bool $messageInFlight = false;

    /** Main requests in flight (between their `kernel.request` and `kernel.finish_request`). */
    private int $requests = 0;

    /** @var WeakMap<ConsoleTerminateEvent, bool> outermost verdict per terminate event */
    private WeakMap $verdicts;

    /**
     * Starts with nothing running.
     */
    public function __construct()
    {
        $this->verdicts = new WeakMap();
    }

    /**
     * Main `kernel.request`: whether it starts the outermost unit (nothing else runs).
     */
    public function requestStarted(): bool
    {
        $this->dropFailingFrames();
        $outermost = $this->isIdle();
        // @igor-ignore - Unit-of-work counter of the main requests in flight (no request data).
        ++$this->requests;

        return $outermost;
    }

    /**
     * Main `kernel.finish_request`.
     */
    public function requestFinished(): void
    {
        $this->dropFailingFrames();
        if ($this->requests > 0) {
            // @igor-ignore - Unit-of-work counter of the main requests in flight (no request data).
            --$this->requests;
        }

        // Runs started inside the request that never terminated end with it.
        foreach ($this->frames as $index => $frame) {
            if ($frame['requests'] > $this->requests) {
                // @igor-ignore - Frames of the running commands (no request data), ended with their request.
                array_splice($this->frames, $index);

                break;
            }
        }
    }

    /**
     * `kernel.terminate` (PHP_INT_MAX, audit NR-b M1): a top-level request boundary. When no command and no message
     * runs, no main request is in flight any more either, even one whose `kernel.finish_request` never came (a
     * throwing `kernel.exception` listener, an `\Error` with `handleAllThrowables` off): the counter starts again at 0.
     */
    public function requestBoundaryEnded(): void
    {
        $this->dropFailingFrames();
        if ($this->frames === [] && !$this->messageInFlight) {
            // @igor-ignore - Unit-of-work counter of the main requests in flight (no request data).
            $this->requests = 0;
        }
    }

    /**
     * Whether nothing runs (`kernel.terminate` of the outermost request: its finish_request already ran).
     */
    public function isIdle(): bool
    {
        return $this->frames === [] && !$this->messageInFlight && $this->requests === 0;
    }

    /**
     * `WorkerMessageReceivedEvent`: a message is in flight.
     */
    public function messageReceived(): void
    {
        $this->dropFailingFrames();
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = true;
    }

    /**
     * Handled, failed or skipped message.
     */
    public function messageFinished(): void
    {
        $this->dropFailingFrames();
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
        // Runs started inside the message that never terminated end with it.
        foreach ($this->frames as $index => $frame) {
            if ($frame['message']) {
                // @igor-ignore - Frames of the running commands (no request data), ended with their message.
                array_splice($this->frames, $index);

                break;
            }
        }
    }

    /**
     * `WorkerStoppedEvent`: no message is in flight any more.
     */
    public function workerStopped(): void
    {
        $this->messageFinished();
    }

    /**
     * `console.command`: pushes the run's frame; returns whether it is the outermost unit.
     */
    public function commandStarted(ConsoleCommandEvent $event): bool
    {
        $this->dropFailingFrames();
        $outermost = $this->isIdle();
        $command   = $event->getCommand();
        if ($command instanceof Command) {
            // @igor-ignore - Frames of the running commands (no request data), removed by their own terminate.
            $this->frames[] = ['command' => $command, 'input' => $event->getInput(), 'outermost' => $outermost, 'failing' => false, 'message' => $this->messageInFlight, 'requests' => $this->requests];
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
     * Whether the run that $event terminates started as the outermost unit (false for a run without a frame).
     */
    public function endsOutermostUnit(ConsoleTerminateEvent $event): bool
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
        // @igor-ignore - Unit-of-work flag restored from the removed frame.
        $this->messageInFlight = $this->frames[$index]['message'];
        // @igor-ignore - Unit-of-work counter restored from the removed frame.
        $this->requests = $this->frames[$index]['requests'];
        // @igor-ignore - Frames of the running commands (no request data), removed by their own terminate or self-heal.
        array_splice($this->frames, $index);
    }
}
