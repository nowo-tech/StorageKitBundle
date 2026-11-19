<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Concurrency;

use Nowo\StorageKitBundle\Concurrency\StorageConflicts;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * REQ-RUNTIME-003 rule 6 (1.7): which failures get the conflict answer, and CONFLICT_001 / CONFLICT_002.
 */
final class StorageConflictsTest extends TestCase
{
    /**
     * @param array{0: string, 1: int} $info SQLSTATE and vendor code
     */
    public static function storageError(array $info): PDOException
    {
        $e            = new PDOException('storage error');
        $e->errorInfo = [$info[0], $info[1], 'storage error'];

        return $e;
    }

    /**
     * @return iterable<string, array{Throwable, bool}>
     */
    public static function failures(): iterable
    {
        yield 'MySQL deadlock 1213' => [self::storageError(['40001', 1213]), true];
        yield 'MySQL lock wait timeout 1205' => [self::storageError(['HY000', 1205]), true];
        yield 'PostgreSQL serialization failure' => [self::storageError(['40001', 7]), true];
        yield 'PostgreSQL deadlock' => [self::storageError(['40P01', 7]), true];
        yield 'PostgreSQL lock_timeout' => [self::storageError(['55P03', 7]), true];
        yield 'wrapped deadlock' => [new RuntimeException('outer', 0, self::storageError(['40001', 1213])), true];
        yield 'runner transient failure' => [new TransientStorageFailure(2), true];
        yield 'wrapped runner transient failure' => [new RuntimeException('outer', 0, new TransientStorageFailure()), true];
        yield 'unique violation' => [self::storageError(['23000', 1062]), false];
        yield 'PostgreSQL unique violation' => [self::storageError(['23505', 7]), false];
        yield 'lost connection' => [self::storageError(['HY000', 2006]), false];
        yield 'domain failure' => [new RuntimeException('no'), false];
    }

    #[DataProvider('failures')]
    public function testOnlyLockConflictsAreConflicts(Throwable $failure, bool $conflict): void
    {
        self::assertSame($conflict, StorageConflicts::isConflict($failure));
    }

    public function testALostSavepointIsAConflictOfUnknownOutcome(): void
    {
        $lost = self::storageError(['3B001', 7]);

        self::assertTrue(StorageConflicts::isConflict($lost));
        self::assertSame(ConflictCode::Committed, StorageConflicts::code(new Request(), $lost));
    }

    public function testNothingCommittedIsRetrySafe(): void
    {
        $request = new Request();
        $ledger  = new CommitLedger();
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));

        self::assertSame(ConflictCode::RetrySafe, StorageConflicts::code($request, self::storageError(['40001', 1213])));
        self::assertSame(ConflictCode::RetrySafe, StorageConflicts::code(new Request(), new TransientStorageFailure()));
    }

    public function testAnEarlierCommitOfTheRequestIsCommitted(): void
    {
        $request = new Request();
        $ledger  = new CommitLedger();
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));
        $ledger->record();

        self::assertSame(ConflictCode::Committed, StorageConflicts::code($request, self::storageError(['40001', 1213])));
    }

    public function testCommittedWrappersAreCommitted(): void
    {
        $deadlock = self::storageError(['40001', 1213]);

        self::assertSame(ConflictCode::Committed, StorageConflicts::code(new Request(), new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, $deadlock, true)));
        self::assertSame(ConflictCode::RetrySafe, StorageConflicts::code(new Request(), new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, $deadlock)));
        $handled = new HandlerFailedException((new Envelope(new stdClass()))->with(new HandledStamp(null, 'first')), [$deadlock]);
        self::assertSame(ConflictCode::Committed, StorageConflicts::code(new Request(), $handled));
        self::assertSame(ConflictCode::Committed, StorageConflicts::code(new Request(), new DelayedMessageHandlingException([$deadlock])));
    }

    public function testTheRetryAfterHintOfTheFirstRunnerFailure(): void
    {
        self::assertSame(7, StorageConflicts::retryAfterHint(new RuntimeException('outer', 0, new TransientStorageFailure(7))));
        self::assertNull(StorageConflicts::retryAfterHint(new TransientStorageFailure()));
        self::assertNull(StorageConflicts::retryAfterHint(self::storageError(['40001', 1213])));
    }
}
