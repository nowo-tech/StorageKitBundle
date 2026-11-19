<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Concurrency;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Storage conflicts of the bundle's writes (REQ-RUNTIME-003 rules 6 and 8, 1.7), on top of transaction-doctrine's
 * classifier: a deadlock, lock wait timeout or serialization failure (MySQL / MariaDB 1213, 1205, 1020; PostgreSQL
 * 40001, 40P01, 55P03), a {@see TransientStorageFailure} (a runner's, or the owner lock's wait timeout), or a lost
 * savepoint of unknown outcome.
 *
 * Unique (and other constraint) violations and lost connections are never conflicts, wherever they sit in the chain:
 * the classifier excludes them. Stateless (static methods only).
 */
final class StorageConflicts
{
    /**
     * Whether the failure gets the conflict answer: transient (a replay can succeed), or a lost savepoint whose commit
     * outcome is unknown. Never true for a unique violation or a lost connection.
     */
    public static function isConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return true;
            }
        }
        $classifier = new TransientFailureClassifier();

        return $classifier->isTransient($failure) || $classifier->isCommitOutcomeUnknown($failure);
    }

    /**
     * The conflict code (transaction-doctrine >= 1.6.1): CONFLICT_002 when the request's commit mark saw a commit
     * (anything the host or a bundle committed earlier in the same request), when the chain proves a commit (a
     * committed ConcurrencyConflict, a Messenger HandlerFailedException carrying a HandledStamp, any
     * DelayedMessageHandlingException) or for a lost savepoint; CONFLICT_001 otherwise.
     *
     * The request's mark is transaction-doctrine's (its bundle with `commit_detection`). 1.7.1 (audit UH-SK-4): `$own`,
     * the bundle's own mark on the commits of its connection, counts too, so a commit earlier in the request answers
     * CONFLICT_002 without that bundle.
     */
    public static function code(Request $request, Throwable $failure, ?CommitMark $own = null): ConflictCode
    {
        $code = ConflictCode::forRequest($request, $failure);

        return $code->retrySafe() && $own?->committedSince() === true ? ConflictCode::Committed : $code;
    }

    /**
     * The Retry-After hint of the first transient failure of the chain, or null (the configured value).
     */
    public static function retryAfterHint(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return $current->retryAfterSeconds();
            }
        }

        return null;
    }
}
