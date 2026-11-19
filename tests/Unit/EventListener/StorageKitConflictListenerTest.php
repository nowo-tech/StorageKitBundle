<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\EventListener;

use Nowo\StorageKitBundle\Controller\FileDownloadController;
use Nowo\StorageKitBundle\Controller\FileUploadController;
use Nowo\StorageKitBundle\Controller\StorageMoveController;
use Nowo\StorageKitBundle\Controller\StorageSettingsController;
use Nowo\StorageKitBundle\EventListener\StorageKitConflictListener;
use Nowo\StorageKitBundle\Tests\Unit\Concurrency\StorageConflictsTest;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function json_decode;

/**
 * REQ-RUNTIME-003 rule 6 (1.7): the bundle routes answer 409 (or 503) + X-Conflict-Code, with Retry-After only for
 * CONFLICT_001; host routes and other failures are left alone.
 */
final class StorageKitConflictListenerTest extends TestCase
{
    private function event(Throwable $failure, mixed $controller, ?CommitLedger $committed = null): ExceptionEvent
    {
        $request = new Request();
        $request->attributes->set('_controller', $controller);
        if ($committed instanceof CommitLedger) {
            $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($committed));
            $committed->record();
        }

        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }

    private function deadlock(): Throwable
    {
        return new RuntimeException('wrapped', 0, StorageConflictsTest::storageError(['40001', 1213]));
    }

    public function testAPageConflictBecomesA409HttpExceptionWithTheCode(): void
    {
        $event = $this->event($this->deadlock(), StorageSettingsController::class . '::instance');
        (new StorageKitConflictListener())($event);

        $exception = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $exception);
        self::assertSame(409, $exception->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '1'], $exception->getHeaders());
        self::assertSame(StorageKitConflictListener::MESSAGE, $exception->getMessage());
        self::assertNull($event->getResponse());
    }

    public function testAMoveConflictAfterACommitIsConflict002WithoutRetryAfter(): void
    {
        $event = $this->event($this->deadlock(), StorageMoveController::class . '::instanceAction', new CommitLedger());
        (new StorageKitConflictListener(new ConflictResponsePolicy(503, 5)))($event);

        $exception = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $exception);
        self::assertSame(503, $exception->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $exception->getHeaders());
        self::assertSame(StorageKitConflictListener::COMMITTED_MESSAGE, $exception->getMessage());
    }

    public function testTheUploadEndpointKeepsItsJsonShapeAndAddsTheCode(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key === 'conflict.retry' ? 'Try again (translated).' : $key);
        $event = $this->event(new TransientStorageFailure(9), FileUploadController::class . '::upload');
        (new StorageKitConflictListener(new ConflictResponsePolicy(), $translator))($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => 'conflict', 'message' => 'Try again (translated).', 'code' => 'CONFLICT_001'], json_decode((string) $response->getContent(), true));
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('9', $response->headers->get('Retry-After'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testTheDownloadEndpointGetsPlainText(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $event = $this->event($this->deadlock(), FileDownloadController::class . '::download', new CommitLedger());
        (new StorageKitConflictListener(new ConflictResponsePolicy(), $translator))($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(StorageKitConflictListener::COMMITTED_MESSAGE, $response->getContent(), 'an untranslated key falls back to the English text');
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));
        self::assertFalse($response->headers->has('Retry-After'));
    }

    public function testHostRoutesAndOtherFailuresAreLeftAlone(): void
    {
        $listener = new StorageKitConflictListener();
        foreach ([
            $this->event($this->deadlock(), 'App\\Controller\\HomeController::index'),
            $this->event($this->deadlock(), null),
            $this->event($this->deadlock(), [new RuntimeException(), 'x']),
            $this->event(StorageConflictsTest::storageError(['23000', 1062]), StorageSettingsController::class . '::instance'),
            $this->event(StorageConflictsTest::storageError(['HY000', 2006]), FileUploadController::class . '::upload'),
            $this->event(new RuntimeException('domain'), StorageMoveController::class . '::instanceAction'),
        ] as $event) {
            $failure = $event->getThrowable();
            $listener($event);
            self::assertSame($failure, $event->getThrowable());
            self::assertNull($event->getResponse());
        }
    }
}
