<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Concurrency;

use Nowo\StorageKitBundle\Sync\StorageMove;
use RuntimeException;

/**
 * A bucket form was saved from a page that is no longer current: someone saved the same bucket after the page was
 * opened (2.1, REQ-RUNTIME-003 rule 4).
 *
 * A domain answer, not a lock conflict: nothing was written, repeating the same request can never succeed, and the
 * admin has to reload the page. The Storage and Move pages catch it and render the form again with a 409 answer and
 * `X-Error-Code: STORAGE_KIT_STALE_EDIT` (never an `X-Conflict-Code`, which is kept for lock conflicts). Host code
 * that calls {@see StorageMove::saveTarget()} with a version catches it too.
 */
final class StaleEditException extends RuntimeException
{
    /** Value of the `X-Error-Code` header of the 409 answer. */
    public const ERROR_CODE = 'STORAGE_KIT_STALE_EDIT';

    /**
     * @param int $id identifier of the bucket (storage profile) row
     * @param int|null $expected the version the page was built from
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $expected,
    ) {
        parent::__construct('The bucket was saved by someone else after the page was opened; nothing was written.');
    }
}
