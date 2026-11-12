<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Persistence;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\StorageKitBundle\Doctrine\OwnEntityManagerSubscriber;
use Nowo\StorageKitBundle\EventListener\UnitOfWorkFrames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageSkipEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Throwable;

/**
 * Audit NX CR1–CR3: the bundle's own manager ({@see OwnEntityManagerSubscriber}, wired from its LISTENERS table as the
 * compiler pass wires it) is cleared only at the outermost unit, through a REAL Messenger worker, a REAL HttpKernel
 * (`handle()` + `terminate()`, no services resetter) and a REAL console Application in one process.
 */
#[CoversClass(OwnEntityManagerSubscriber::class)]
#[CoversClass(UnitOfWorkFrames::class)]
final class UnitBoundaryTrackerNxTest extends TestCase
{
    private int $clears = 0;

    private EventDispatcher $dispatcher;

    private Application $application;

    private HttpKernel $kernel;

    /** The HTTP kernel's request stack, shared with the subscriber under test. */
    private RequestStack $requestStack;

    /**
     * @return iterable<string, array{Closure(self): void}>
     */
    public static function subscribers(): iterable
    {
        yield 'own entity manager' => [static fn (self $test) => $test->wireOwnEntityManagerSubscriber()];
    }

    public function wireOwnEntityManagerSubscriber(): void
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('clear')->willReturnCallback(function (): void {
            ++$this->clears;
        });
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $subscriber = new OwnEntityManagerSubscriber($registry, 'storage', $this->requestStack);
        foreach (OwnEntityManagerSubscriber::LISTENERS as [$event, $method, $priority]) {
            $this->dispatcher->addListener($event, [$subscriber, $method], $priority);
        }
    }

    /**
     * Audit NX CR1: a worker run in-process inside a message handler, then a main request in the same handler: neither
     * clears the outer message's unit; the outer message clears when received and when handled.
     *
     * @param Closure(self): void $factory
     */
    #[DataProvider('subscribers')]
    public function testAWorkerInsideAHandlerNeverEndsTheOuterMessage(Closure $factory): void
    {
        $this->boot($factory);
        $inside = null;
        $this->runWorker(1, function () use (&$inside): void {
            $before = $this->clears;
            $this->runWorker(1, static function (): void {
            });
            $request = Request::create('/in-handler');
            $request->attributes->set('_controller', static fn (): Response => new Response('in handler'));
            $this->kernel->handle($request);
            $inside = $this->clears - $before;
        });

        self::assertSame(0, $inside, 'The inner worker and the in-process request keep the outer message.');
        self::assertSame(2, $this->clears);
        $this->assertNextUnitsClear();
    }

    /**
     * Audit NX CR3: a received listener skips the message with `shouldHandle(false)` (the Worker reports neither handled
     * nor failed); without any worker event at all (CR3b) the message ends too.
     *
     * @param Closure(self): void $factory
     */
    #[DataProvider('subscribers')]
    public function testASkippedMessageEndsWithoutAHandledEvent(Closure $factory): void
    {
        $this->boot($factory);
        $received = 0;
        $skip     = static function (WorkerMessageReceivedEvent $event) use (&$received): void {
            if (++$received === 1) {
                $event->shouldHandle(false);
            }
        };
        $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, $skip);
        // The in-memory transport delivers the skipped (never acknowledged) message again.
        $this->runWorker(1, static function (): void {
        });
        $this->dispatcher->removeListener(WorkerMessageReceivedEvent::class, $skip);
        $this->assertNextUnitsClear();

        $event = new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async');
        $event->shouldHandle(false);
        $this->dispatcher->dispatch($event);
        $this->assertNextUnitsClear();
    }

    /**
     * A message dispatched synchronously (no worker) inside a request belongs to the request.
     *
     * @param Closure(self): void $factory
     */
    #[DataProvider('subscribers')]
    public function testASynchronousMessageInsideARequestBelongsToTheRequest(Closure $factory): void
    {
        $this->boot($factory);
        $handled = 0;
        $bus     = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([stdClass::class => [static function () use (&$handled): void {
            ++$handled;
        }]]))]);
        $inside = null;
        $this->handleRequest(function () use ($bus, &$inside): void {
            $before = $this->clears;
            $bus->dispatch(new stdClass());
            $inside = $this->clears - $before;
        });

        self::assertSame(1, $handled);
        self::assertSame(0, $inside);
        self::assertSame(2, $this->clears);
        $this->assertNextUnitsClear();
    }

    /**
     * A `messenger:consume`-like command runs a worker in-process: every message is an outermost unit inside it.
     *
     * @param Closure(self): void $factory
     */
    #[DataProvider('subscribers')]
    public function testAWorkerInsideACommandClearsAtEveryMessage(Closure $factory): void
    {
        $this->boot($factory);
        $this->application->addCommand((new Command('app:consume'))->setCode(function (): int {
            $this->runWorker(2, static function (): void {
            });

            return Command::SUCCESS;
        }));
        $this->runCommand('app:consume');

        self::assertSame(2 + 2 * 2, $this->clears, 'The command start / end and every message received / handled.');
        $this->assertNextUnitsClear();
    }

    /**
     * Two workers one after the other; the first one's stop never came (a WorkerRunningEvent listener threw out of
     * `Worker::run()`): the second worker's messages are still outermost units.
     *
     * @param Closure(self): void $factory
     */
    #[DataProvider('subscribers')]
    public function testTwoSequentialWorkersKeepTheirOwnState(Closure $factory): void
    {
        $this->boot($factory);
        $this->runWorker(1, static function (): void {
        });
        self::assertSame(2, $this->clears);

        $throw = static function (): never {
            throw new RuntimeException('running listener failed');
        };
        $this->dispatcher->addListener(WorkerRunningEvent::class, $throw);
        try {
            $this->runWorker(1, static function (): void {
            });
            self::fail('The running listener throws.');
        } catch (RuntimeException) {
        } finally {
            $this->dispatcher->removeListener(WorkerRunningEvent::class, $throw);
        }

        $before = $this->clears;
        $this->runWorker(1, static function (): void {
        });
        self::assertSame($before + 2, $this->clears, 'The second worker\'s message clears when received and when handled.');
        $this->assertNextUnitsClear();
    }

    /**
     * Unbalanced events: another worker's running / stopped events inside a message do not end it; a nested main
     * request whose end never came is popped with its outer request.
     *
     * @param Closure(self): void $factory
     */
    #[DataProvider('subscribers')]
    public function testUnbalancedEventsNeverEndAnotherUnit(Closure $factory): void
    {
        $this->boot($factory);
        $inside = null;
        $this->runWorker(1, function () use (&$inside): void {
            $stranger = (new ReflectionClass(Worker::class))->newInstanceWithoutConstructor();
            $this->dispatcher->dispatch(new WorkerRunningEvent($stranger, false));
            $this->dispatcher->dispatch(new WorkerStoppedEvent($stranger));
            $before  = $this->clears;
            $request = Request::create('/in-handler');
            $request->attributes->set('_controller', static fn (): Response => new Response('in handler'));
            $this->kernel->handle($request);
            $inside = $this->clears - $before;
        });
        self::assertSame(0, $inside, 'Another worker\'s events do not end this message.');

        $this->handleRequest(function (): void {
            // A nested main request whose finish_request / terminate never came.
            $this->dispatcher->dispatch(new RequestEvent($this->kernel, Request::create('/lost'), HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        });
        $this->assertNextUnitsClear();
    }

    /**
     * Exceptions in the middle of a unit: a failing handler, a received listener that throws out of an in-process
     * worker outside any command (the next top-level terminate resets), and a top-level request whose
     * `kernel.exception` listener throws without any terminate (the HTTP kernel's request stack tells it is over).
     *
     * @param Closure(self): void $factory
     */
    #[DataProvider('subscribers')]
    public function testAnExceptionMidUnitNeverLeavesAUnitInFlight(Closure $factory): void
    {
        $this->boot($factory);
        $this->runWorker(1, static function (): never {
            throw new RuntimeException('handler failed');
        });
        self::assertSame(2, $this->clears, 'Received and failed.');

        $throw = static function (): never {
            throw new RuntimeException('listener failed');
        };
        $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, $throw);
        try {
            $this->runWorker(1, static function (): void {
            });
            self::fail('The received listener throws.');
        } catch (RuntimeException) {
        } finally {
            $this->dispatcher->removeListener(WorkerMessageReceivedEvent::class, $throw);
        }
        $this->handleRequest();
        $this->assertNextUnitsClear();

        $this->dispatcher->addListener(KernelEvents::EXCEPTION, $throw);
        $request = Request::create('/');
        $request->attributes->set('_controller', static function (): never {
            throw new RuntimeException('controller failed');
        });
        try {
            $this->kernel->handle($request);
            self::fail('The kernel.exception listener throws.');
        } catch (RuntimeException) {
        } finally {
            $this->dispatcher->removeListener(KernelEvents::EXCEPTION, $throw);
        }
        $this->assertNextUnitsClear();
    }

    /**
     * Runs a real Messenger worker over $count messages until it is idle.
     */
    /**
     * Audit OB-M1 (S8): a long-running command runs workers in a loop and catches their exceptions; the first run aborts
     * in a throwing received listener. Every later message still clears when received and when handled.
     *
     * @param Closure(self): void $factory
     */
    #[DataProvider('subscribers')]
    public function testAnAbortedWorkerInsideALongCommandNeverStopsTheNextMessagesFromClearing(Closure $factory): void
    {
        $this->boot($factory);
        $perWorker = [];
        $this->application->addCommand((new Command('app:loop'))->setCode(function () use (&$perWorker): int {
            $throw = static function (): never {
                throw new RuntimeException('received listener failed');
            };
            $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, $throw);
            try {
                $this->runWorker(1, static function (): void {
                });
            } catch (RuntimeException) {
            } finally {
                $this->dispatcher->removeListener(WorkerMessageReceivedEvent::class, $throw);
            }

            for ($i = 0; $i < 3; ++$i) {
                $before = $this->clears;
                $this->runWorker(2, static function (): void {
                });
                $perWorker[] = $this->clears - $before;
            }

            return Command::SUCCESS;
        }));
        $this->runCommand('app:loop');

        self::assertSame([4, 4, 4], $perWorker);
        $this->assertNextUnitsClear();
    }

    /**
     * Audit OB-L1 (S13): 500 runs aborted by a throwing handled listener inside one command; the next worker's messages
     * are still the outermost units.
     *
     * @param Closure(self): void $factory
     */
    #[DataProvider('subscribers')]
    public function testManyAbortedRunsKeepTheNextWorkerOutermost(Closure $factory): void
    {
        $this->boot($factory);
        $clears = null;
        $this->application->addCommand((new Command('app:loop'))->setCode(function () use (&$clears): int {
            $throw = static function (): never {
                throw new RuntimeException('handled listener failed');
            };
            $this->dispatcher->addListener(WorkerMessageHandledEvent::class, $throw, -1);
            for ($i = 0; $i < 500; ++$i) {
                try {
                    $this->runWorker(1, static function (): void {
                    });
                } catch (RuntimeException) {
                }
            }
            $this->dispatcher->removeListener(WorkerMessageHandledEvent::class, $throw);
            $before = $this->clears;
            $this->runWorker(2, static function (): void {
            });
            $clears = $this->clears - $before;

            return Command::SUCCESS;
        }));
        $this->runCommand('app:loop');

        self::assertSame(4, $clears);
        $this->assertNextUnitsClear();
    }

    /**
     * Audit OB-L2 (S9): a received listener of an inner worker dispatches `WorkerMessageSkipEvent` and skips the
     * message: only the inner message ends, a main request in the outer handler does not clear.
     *
     * @param Closure(self): void $factory
     */
    #[DataProvider('subscribers')]
    public function testASkipEventFromAReceivedListenerNeverEndsTheOuterMessage(Closure $factory): void
    {
        $this->boot($factory);
        $inside = null;
        $this->runWorker(1, function () use (&$inside): void {
            $skip = function (WorkerMessageReceivedEvent $event): void {
                $this->dispatcher->dispatch(new WorkerMessageSkipEvent($event->getEnvelope(), $event->getReceiverName()));
                $event->shouldHandle(false);
            };
            $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, $skip);
            $this->runWorker(1, static function (): void {
            }, true);
            $this->dispatcher->removeListener(WorkerMessageReceivedEvent::class, $skip);
            $before  = $this->clears;
            $request = Request::create('/in-handler');
            $request->attributes->set('_controller', static fn (): Response => new Response('in handler'));
            $this->kernel->terminate($request, $this->kernel->handle($request));
            $inside = $this->clears - $before;
        });

        self::assertSame(0, $inside);
        $this->assertNextUnitsClear();
    }

    /**
     * Audit OB-L3 (S10): the in-process `kernel.terminate` of a nested main request does not clear the outer request.
     *
     * @param Closure(self): void $factory
     */
    #[DataProvider('subscribers')]
    public function testTheTerminateOfANestedMainRequestKeepsTheOuterRequest(Closure $factory): void
    {
        $this->boot($factory);
        $inside = null;
        $this->handleRequest(function () use (&$inside): void {
            $before = $this->clears;
            $this->handleRequest();
            $inside = $this->clears - $before;
        });

        self::assertSame(0, $inside);
        self::assertSame(2, $this->clears);
        $this->assertNextUnitsClear();
    }

    private function runWorker(int $count, Closure $handler, bool $stopAfterFirst = false): void
    {
        $transport = new InMemoryTransport();
        for ($i = 0; $i < $count; ++$i) {
            $transport->send(new Envelope(new stdClass()));
        }

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use ($handler): Envelope {
            $handler();

            return $message instanceof Envelope ? $message : new Envelope($message);
        });
        $worker = new Worker(['async' => $transport], $bus, $this->dispatcher);
        $stop   = static function (WorkerRunningEvent $event) use ($worker, $stopAfterFirst): void {
            if ($event->getWorker() === $worker && ($stopAfterFirst || $event->isWorkerIdle())) {
                $worker->stop();
            }
        };
        $this->dispatcher->addListener(WorkerRunningEvent::class, $stop, -1);
        try {
            $worker->run(['sleep' => 0]);
        } finally {
            $this->dispatcher->removeListener(WorkerRunningEvent::class, $stop);
        }
    }

    private function assertNextUnitsClear(): void
    {
        $before = $this->clears;
        $this->handleRequest();
        self::assertSame($before + 2, $this->clears, 'The next main request must clear at its start and terminate.');
        $this->runCommand('app:noop');
        self::assertSame($before + 4, $this->clears, 'The next outermost command must clear at its start and end.');
    }

    /**
     * @param Closure(self): void $factory
     */
    private function boot(Closure $factory): void
    {
        $this->clears       = 0;
        $this->dispatcher   = new EventDispatcher();
        $this->requestStack = new RequestStack();
        $factory($this);

        $this->kernel      = new HttpKernel($this->dispatcher, new ControllerResolver(), $this->requestStack, new ArgumentResolver());
        $this->application = new Application();
        $this->application->setDispatcher($this->dispatcher);
        $this->application->setAutoExit(false);
        $this->application->setCatchExceptions(false);
        $this->application->addCommand((new Command('app:noop'))->setCode(static fn (): int => Command::SUCCESS));
    }

    private function runCommand(string $name): void
    {
        try {
            $this->application->doRun(new ArrayInput(['command' => $name]), new NullOutput());
        } catch (Throwable) {
            // The failing listeners and commands of the tests.
        }
    }

    /**
     * @param (Closure(): void)|null $controller
     */
    private function handleRequest(?Closure $controller = null): void
    {
        $request = Request::create('/');
        $request->attributes->set('_controller', static function () use ($controller): Response {
            if ($controller instanceof Closure) {
                $controller();
            }

            return new Response('ok');
        });
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
    }
}
