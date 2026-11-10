<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\PersonalData;

/**
 * Port: the stable key of a user, stored next to the uploader's user identifier (`uploaded_by_key`) so the GDPR export
 * and erasure still find a person's files after their identifier (often an e-mail) changes.
 *
 * The key must never change for one person and never be given to another one (a database id or a UUID, not an e-mail
 * or a user name). Null when the user has none: the file is then matched by the user identifier only, as before 1.2.0.
 * Configure your own with `personal_data.uploader_key_resolver`; the default is {@see UserIdUploaderKeyResolver}.
 */
interface UploaderKeyResolverInterface
{
    /** Longest stored key (longer keys are refused, never cut: a cut key could match another person). */
    public const MAX_LENGTH = 180;

    /**
     * The stable key of `$user` (the security user given to the uploader), or null.
     */
    public function keyOf(object $user): ?string;
}
