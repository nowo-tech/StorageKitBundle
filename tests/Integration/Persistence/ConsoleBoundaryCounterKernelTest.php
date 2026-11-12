<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Persistence;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\EventListener\RequestBoundaryListener;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function is_array;
use function is_string;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * Audit NM (1.5.1): the console bookkeeping of the boundary listeners survives listeners that throw or stop the
 * propagation. A REAL console Application runs commands in-process on the kernel's own event dispatcher and the same
 * kernel then handles main requests (kept-alive worker, services resetter never called).
 */
final class ConsoleBoundaryCounterKernelTest extends KernelTestCase
{
    private const REQUEST_PATH = '/storage/files/00000000-0000-4000-8000-000000000000/original';

    /** Whether the bundle manager still held the outer command's row after its nested command ended. */
    private ?bool $outerKept = null;

    /** Whether the probe command started with an empty bundle manager. */
    private ?bool $probeStartedEmpty = null;

    private int $resets = 0;

    /** The next console.terminate listener call throws (once). */
    private bool $failTerminateOnce = false;

    /**
     * (a) A console.terminate listener at priority 0 throws: the next main request and the next command still clear
     * the dedicated manager.
     */
    public function testATerminateListenerThatThrowsDoesNotStopTheNextClears(): void
    {
        [$kernel, $dispatcher, $application] = $this->dedicated();
        $failed                              = false;
        $dispatcher->addListener(ConsoleEvents::TERMINATE, static function () use (&$failed): void {
            if (!$failed) {
                $failed = true;

                throw new RuntimeException('terminate listener failed once');
            }
        });

        $this->runCommand($application, 'app:noop');
        $this->assertNextUnitsClear($kernel, $application);
    }

    /**
     * (b) A console.error listener throws (Symfony then skips console.terminate): the next main request and the next
     * command still clear the dedicated manager.
     */
    public function testAnErrorListenerThatThrowsDoesNotStopTheNextClears(): void
    {
        [$kernel, $dispatcher, $application] = $this->dedicated();
        $dispatcher->addListener(ConsoleEvents::ERROR, static function (): never {
            throw new RuntimeException('error listener failed');
        });

        $this->runCommand($application, 'app:fail');
        $this->assertNextUnitsClear($kernel, $application);
    }

    /**
     * (c) The console.terminate of a nested command that was never seen starting does not end the outer unit.
     */
    public function testAnUnknownNestedCommandDoesNotEndTheOuterUnit(): void
    {
        [$kernel, $dispatcher, $application] = $this->dedicated(static function (EventDispatcherInterface $dispatcher): void {
            $dispatcher->dispatch(new ConsoleTerminateEvent(new Command('app:unknown'), new ArrayInput([]), new NullOutput(), 0), ConsoleEvents::TERMINATE);
        });

        $this->runCommand($application, 'app:outer');
        self::assertTrue($this->outerKept, 'The unknown nested command must not clear the outer unit mid-run.');
        $this->assertNextUnitsClear($kernel, $application);
    }

    /**
     * (d) A console.command listener above 4096 stops the propagation for the nested command: the outer unit is not
     * cleared mid-run.
     */
    public function testAStoppedNestedCommandEventDoesNotClearTheOuterUnit(): void
    {
        [$kernel, $dispatcher, $application] = $this->dedicated();
        $dispatcher->addListener(ConsoleEvents::COMMAND, static function (ConsoleCommandEvent $event): void {
            if ($event->getCommand()?->getName() === 'app:nested') {
                $event->stopPropagation();
            }
        }, 5000);

        $this->runCommand($application, 'app:outer');
        self::assertTrue($this->outerKept, 'The nested command must not clear the outer unit mid-run.');
        $this->assertNextUnitsClear($kernel, $application);
    }

    /**
     * (e) Unchanged behaviour: a nested command keeps the outer unit, the outermost end clears, main requests clear.
     */
    public function testNestedCommandsAndMainRequestsKeepTheirBehaviour(): void
    {
        [$kernel, , $application] = $this->dedicated();

        $this->runCommand($application, 'app:outer');
        self::assertTrue($this->outerKept);
        $profile = $this->profile($kernel);
        $this->runCommand($application, 'app:nested');
        self::assertFalse($this->bundle($kernel)->contains($profile), 'An outermost command clears at its end.');
        $this->assertNextUnitsClear($kernel, $application);
    }

    /**
     * Audit NL2-v: a command that runs itself in-process, whose inner run fails and whose error listener throws (no
     * terminate for the inner run): the outer run keeps its unit and the next units still clear.
     */
    public function testASelfRecursiveCommandWithAThrowingErrorListener(): void
    {
        [$kernel, $dispatcher, $application] = $this->dedicated();
        $runs                                = 0;
        $application->addCommand((new Command('app:recursive'))->setCode(function () use (&$runs, $application, $dispatcher, $kernel): int {
            if (++$runs > 1) {
                throw new RuntimeException('inner run failed');
            }
            $profile = $this->profile($kernel);
            $throw   = static function (): never {
                throw new RuntimeException('error listener failed');
            };
            $dispatcher->addListener(ConsoleEvents::ERROR, $throw);
            $this->runCommand($application, 'app:recursive');
            $dispatcher->removeListener(ConsoleEvents::ERROR, $throw);
            $this->outerKept = $this->bundle($kernel)->contains($profile);

            return Command::SUCCESS;
        }));

        $this->runCommand($application, 'app:recursive');
        self::assertTrue($this->outerKept, 'The failed inner run must not clear the outer run.');
        $this->assertNextUnitsClear($kernel, $application);
    }

    /**
     * Audit NL2-v: an error listener at PHP_INT_MIN throws; a WorkerMessageReceivedEvent listener throws inside an
     * in-process consume command (no handled / failed / stopped event); a main request handled in-process inside
     * another one. The next units still clear and the outer request keeps its unit.
     */
    public function testLowestPriorityErrorListenerReceivedListenerAndNestedMainRequest(): void
    {
        [$kernel, $dispatcher, $application] = $this->dedicated();
        $throw                               = static function (): never {
            throw new RuntimeException('listener failed');
        };
        $dispatcher->addListener(ConsoleEvents::ERROR, $throw, PHP_INT_MIN);
        $this->runCommand($application, 'app:fail');
        $dispatcher->removeListener(ConsoleEvents::ERROR, $throw);
        $this->assertNextUnitsClear($kernel, $application);

        $application->addCommand((new Command('app:consume'))->setCode(static function () use ($dispatcher, $throw): int {
            $dispatcher->addListener(NowoStorageKitExtension::WORKER_MESSAGE_RECEIVED, $throw);
            try {
                $dispatcher->dispatch(new WorkerMessageReceivedEvent(new Envelope(new stdClass()), 'async'));
            } finally {
                $dispatcher->removeListener(NowoStorageKitExtension::WORKER_MESSAGE_RECEIVED, $throw);
            }

            return Command::SUCCESS;
        }));
        $this->runCommand($application, 'app:consume');
        $this->assertNextUnitsClear($kernel, $application);

        $http = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $outer   = Request::create(self::REQUEST_PATH, server: ['REMOTE_ADDR' => '203.0.113.9']);
        $profile = null;
        $kept    = null;
        $dispatcher->addListener(KernelEvents::CONTROLLER, $nested = function () use ($http, $kernel, &$profile, &$kept, &$nested, $dispatcher): void {
            $dispatcher->removeListener(KernelEvents::CONTROLLER, $nested);
            $profile = $this->profile($kernel);
            $inner   = Request::create(self::REQUEST_PATH, server: ['REMOTE_ADDR' => '203.0.113.9']);
            $http->terminate($inner, $http->handle($inner));
            $kept = $this->bundle($kernel)->contains($profile);
        });
        $http->terminate($outer, $http->handle($outer));
        self::assertTrue($kept, 'A nested main request neither clears nor ends the outer request.');
        self::assertInstanceOf(StorageProfile::class, $profile);
        self::assertFalse($this->bundle($kernel)->contains($profile), 'The outer request clears at its end.');
        $this->assertNextUnitsClear($kernel, $application);
    }

    /**
     * Audit NL2-v2: a message handler runs an in-process command that fails with a throwing console.error listener (no
     * terminate), the message fails, the worker stops: no message stays in flight and the next units still clear.
     */
    public function testAFailedCommandInsideAFailedMessageThenAStoppedWorker(): void
    {
        [$kernel, $dispatcher, $application] = $this->dedicated();
        $throw                               = static function (): never {
            throw new RuntimeException('error listener failed');
        };
        $envelope = new Envelope(new stdClass());

        $dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'async'));
        $dispatcher->addListener(ConsoleEvents::ERROR, $throw);
        $this->runCommand($application, 'app:fail');
        $dispatcher->removeListener(ConsoleEvents::ERROR, $throw);
        $dispatcher->dispatch(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('handler failed')));
        $dispatcher->dispatch(new WorkerStoppedEvent((new ReflectionClass(Worker::class))->newInstanceWithoutConstructor()));
        $this->assertNextUnitsClear($kernel, $application);
    }

    /**
     * The request-memo listener, wired exactly as the kernel wires it (same events, methods and priorities), with a
     * counting memo: a throwing terminate / error listener, an unknown nested command and a stopped nested
     * console.command no longer stop or shift the end-of-command reset; a command run by a controller that never
     * terminates is dropped when the request ends.
     */
    public function testTheRequestMemoListenerKeepsCountingCommands(): void
    {
        $dispatcher = $this->memoDispatcher(static function (EventDispatcher $dispatcher): void {
            // Before the bookkeeping at the same priority: hides the error and the terminate of a failing command.
            $dispatcher->addListener(ConsoleEvents::ERROR, static function (): never {
                throw new RuntimeException('error listener failed');
            }, PHP_INT_MAX);
        });
        $dispatcher->addListener(ConsoleEvents::TERMINATE, function (): void {
            if ($this->failTerminateOnce) {
                $this->failTerminateOnce = false;

                throw new RuntimeException('terminate listener failed once');
            }
        }, 1);
        $dispatcher->addListener(ConsoleEvents::COMMAND, static function (ConsoleCommandEvent $event): void {
            if ($event->getCommand()?->getName() === 'app:nested') {
                $event->stopPropagation();
            }
        }, 5000);
        $application = $this->application($dispatcher, static function () use ($dispatcher): void {
            $dispatcher->dispatch(new ConsoleTerminateEvent(new Command('app:unknown'), new ArrayInput([]), new NullOutput(), 0), ConsoleEvents::TERMINATE);
        });
        $http = new HttpKernel($dispatcher, new ControllerResolver(), new RequestStack(), new ArgumentResolver());

        $this->runCommand($application, 'app:outer');
        self::assertSame(1, $this->resets, 'Only the outermost command end resets.');
        $this->failTerminateOnce = true;
        $this->runCommand($application, 'app:noop');
        self::assertSame(1, $this->resets, 'The throwing listener skipped this end.');
        $this->runCommand($application, 'app:noop');
        self::assertSame(2, $this->resets, 'The next outermost command end resets again.');

        // A command run by a controller whose error listener throws (no console.terminate): dropped with the request.
        $request = Request::create('/');
        $request->attributes->set('_controller', function () use ($application): Response {
            $this->runCommand($application, 'app:fail');

            return new Response('ok');
        });
        $http->terminate($request, $http->handle($request));
        self::assertSame(4, $this->resets, 'The request resets at its start and at kernel.terminate.');
        $this->runCommand($application, 'app:noop');
        self::assertSame(5, $this->resets, 'The next outermost command end resets again.');
    }

    private function assertNextUnitsClear(StorageKitTestKernel $kernel, Application $application): void
    {
        $http = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $profile  = $this->profile($kernel);
        $request  = Request::create(self::REQUEST_PATH, server: ['REMOTE_ADDR' => '203.0.113.9']);
        $response = $http->handle($request);
        self::assertFalse($this->bundle($kernel)->contains($profile), 'The next main request starts with an empty manager.');
        $profile = $this->profile($kernel);
        $http->terminate($request, $response);
        self::assertFalse($this->bundle($kernel)->contains($profile), 'The next main request ends with an empty manager.');

        $this->profile($kernel);
        $this->runCommand($application, 'app:probe');
        self::assertTrue($this->probeStartedEmpty, 'The next outermost command starts with an empty manager.');
    }

    /**
     * @param (Closure(EventDispatcherInterface): void)|null $inOuter runs inside app:outer, after app:nested
     *
     * @return array{StorageKitTestKernel, EventDispatcherInterface, Application}
     */
    private function dedicated(?Closure $inOuter = null): array
    {
        $kernel = $this->bootKernel(['doctrine' => ['entity_manager' => 'storage_kit']], orm: [
            'default_entity_manager' => 'default',
            'entity_managers'        => [
                'default'     => ['connection' => 'default', 'auto_mapping' => true, 'mappings' => ['Tests' => StorageKitTestKernel::testsMapping()]],
                'storage_kit' => ['connection' => 'default'],
            ],
        ]);
        $dispatcher = $this->service($kernel, 'event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $application = $this->application($dispatcher, $inOuter instanceof Closure ? static fn () => $inOuter($dispatcher) : null, $kernel);

        return [$kernel, $dispatcher, $application];
    }

    /**
     * @param (Closure(): void)|null $inOuter
     */
    private function application(EventDispatcherInterface $dispatcher, ?Closure $inOuter = null, ?StorageKitTestKernel $kernel = null): Application
    {
        $application = new Application();
        $application->setDispatcher($dispatcher);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->addCommand((new Command('app:noop'))->setCode(static fn (): int => Command::SUCCESS));
        $application->addCommand((new Command('app:nested'))->setCode(static fn (): int => Command::SUCCESS));
        $application->addCommand((new Command('app:fail'))->setCode(static function (): never {
            throw new RuntimeException('command failed');
        }));
        $application->addCommand((new Command('app:probe'))->setCode(function () use ($kernel): int {
            $this->probeStartedEmpty = $kernel instanceof StorageKitTestKernel && $this->bundle($kernel)->getUnitOfWork()->size() === 0;

            return Command::SUCCESS;
        }));
        $application->addCommand((new Command('app:outer'))->setCode(function () use ($application, $inOuter, $kernel): int {
            $profile = $kernel instanceof StorageKitTestKernel ? $this->profile($kernel) : null;
            $this->runCommand($application, 'app:nested');
            if ($inOuter instanceof Closure) {
                $inOuter();
            }
            $this->outerKept = $kernel instanceof StorageKitTestKernel && $profile instanceof StorageProfile && $this->bundle($kernel)->contains($profile);

            return Command::SUCCESS;
        }));

        return $application;
    }

    /**
     * A fresh dispatcher with a {@see RequestBoundaryListener} on a counting memo, registered on the events, methods and
     * priorities the kernel registers the bundle's own listener with.
     *
     * @param Closure(EventDispatcher): void $before listeners registered before the bundle listener
     */
    private function memoDispatcher(Closure $before): EventDispatcher
    {
        $kernel    = $this->bootKernel();
        $container = $this->service($kernel, 'event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $container);
        $memo = $this->createStub(ResetInterface::class);
        $memo->method('reset')->willReturnCallback(function (): void {
            ++$this->resets;
        });
        $listener   = new RequestBoundaryListener([$memo]);
        $dispatcher = new EventDispatcher();
        $before($dispatcher);
        $wired = 0;
        /** @var array<string, list<callable>> $all */
        $all = $container->getListeners();
        foreach ($all as $event => $listeners) {
            foreach ($listeners as $registered) {
                if (is_array($registered) && $registered[0] instanceof RequestBoundaryListener && is_string($registered[1])) {
                    $dispatcher->addListener($event, [$listener, $registered[1]], $container->getListenerPriority($event, $registered) ?? 0);
                    ++$wired;
                }
            }
        }
        self::assertSame(PHP_INT_MAX, $dispatcher->getListenerPriority(ConsoleEvents::COMMAND, [$listener, 'onConsoleCommand']));
        self::assertSame(PHP_INT_MAX, $dispatcher->getListenerPriority(KernelEvents::REQUEST, [$listener, 'onKernelRequest']));
        self::assertGreaterThanOrEqual(8, $wired);

        return $dispatcher;
    }

    private function bundle(StorageKitTestKernel $kernel): EntityManagerInterface
    {
        return $this->service($kernel, StorageEntityManager::class)->manager();
    }

    private function runCommand(Application $application, string $name): void
    {
        try {
            $application->doRun(new ArrayInput(['command' => $name]), new NullOutput());
        } catch (Throwable) {
            // The failing listeners and commands above.
        }
    }
}
