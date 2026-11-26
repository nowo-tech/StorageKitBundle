<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use Closure;
use WeakMap;

/**
 * The object clean-up a variant write made inside the host's transaction still owes (2.2, audit ZCK-SK-L1), kept
 * outside the returned {@see StoredFileView}: after the host's commit the replaced object is deleted
 * ({@see FileVariantWriterInterface::afterVariantCommit()}), after its rollback the new one
 * ({@see FileVariantWriterInterface::afterVariantRollback()}).
 *
 * Keyed by the view in a {@see WeakMap}: the entry dies with the view, so nothing outlives the request in a FrankenPHP
 * worker (no reset needed, REQ-RUNTIME-002). The closures hold the bucket connection; no dump of the view shows them.
 *
 * @internal
 */
final class PendingVariantObjects
{
    /** @var WeakMap<StoredFileView, array{commit: Closure(): void, rollback: Closure(): void}>|null */
    private static ?WeakMap $pending = null; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (WeakMap keyed by the per-request view)

    /**
     * Remembers what to delete after the commit and after a rollback of the write that returned `$view`.
     *
     * @param Closure(): void $commit
     * @param Closure(): void $rollback
     */
    public static function put(StoredFileView $view, Closure $commit, Closure $rollback): void
    {
        // @igor-ignore - WeakMap keyed by the per-request view; entries are collected with it.
        self::$pending ??= new WeakMap(); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
        // @igor-ignore - WeakMap keyed by the per-request view; entries are collected with it.
        self::$pending[$view] = ['commit' => $commit, 'rollback' => $rollback]; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
    }

    /**
     * Runs and forgets the clean-up of `$view` for `$outcome` (`commit` or `rollback`); nothing when none is owed.
     *
     * @param 'commit'|'rollback' $outcome
     */
    public static function settle(StoredFileView $view, string $outcome): void
    {
        $entry = self::$pending[$view] ?? null;
        if ($entry === null) {
            return;
        }
        // @igor-ignore - WeakMap keyed by the per-request view; the entry is settled once.
        unset(self::$pending[$view]); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
        ($entry[$outcome])();
    }
}
