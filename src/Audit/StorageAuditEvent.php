<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Audit;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after every mutation of the bundle (a profile saved or tested, a move step, an upload, a purge run). Hosts
 * listen to it to feed their audit log (AuditKit, a user-action recorder…). It never carries a secret, an access key, a
 * signed URL or a provider message: only the action, the scope, the actor's user identifier and plain details (the
 * names of the changed fields, ids, counters).
 */
final class StorageAuditEvent extends Event
{
    /** A profile was saved (details: profile, fields). */
    public const PROFILE_SAVED = 'profile_saved';

    /** *Test connection* ran (details: profile, ok, step, reason). */
    public const PROFILE_TESTED = 'profile_tested';

    /** A move target was saved (details: profile, fields). */
    public const MOVE_TARGET_SAVED = 'move_target_saved';

    /** A move target was discarded (details: profile). */
    public const MOVE_TARGET_DISCARDED = 'move_target_discarded';

    /** A move sync was started or resumed (details: sync, from, to). */
    public const MOVE_SYNC_STARTED = 'move_sync_started';

    /** A move sync was cancelled (details: sync). */
    public const MOVE_SYNC_CANCELLED = 'move_sync_cancelled';

    /** Retired profiles no file points at were deleted with their credentials (details: profiles). */
    public const PROFILES_FORGOTTEN = 'profiles_forgotten';

    /** The owner switched to the target bucket (details: from, to, files). */
    public const MOVE_SWITCHED = 'move_switched';

    /** A file was uploaded (details: file, context, bytes). */
    public const FILE_UPLOADED = 'file_uploaded';

    /** The purge ran (details: counters). */
    public const FILES_PURGED = 'files_purged';

    /** A user's files were erased (details: files). */
    public const FILES_ERASED = 'files_erased';

    /**
     * @param string|null $scope scope key, null = the instance
     * @param string|null $actor user identifier, null = console / worker
     * @param array<string, scalar|null> $details plain details
     */
    public function __construct(
        public readonly string $action,
        public readonly ?string $scope = null,
        public readonly ?string $actor = null,
        public readonly array $details = [],
    ) {
    }
}
