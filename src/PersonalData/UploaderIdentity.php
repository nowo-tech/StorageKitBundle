<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\PersonalData;

use Nowo\StorageKitBundle\Doctrine\UploaderCollationListener;

use function mb_check_encoding;
use function mb_strlen;
use function preg_match;

/**
 * Exact-match rules for the uploader identity stored on a file (`uploaded_by`, `uploaded_by_key`), house rule LQ
 * (1.5.2).
 *
 * MySQL and MariaDB compare strings with the column collation: the host defaults (`utf8mb4_0900_ai_ci`,
 * `utf8mb4_unicode_ci`, `utf8mb4_general_ci`, MariaDB `utf8mb4_uca1400_ai_ci`) fold case and accents, and the PAD SPACE
 * ones (every one of those except `utf8mb4_0900_ai_ci`, and the `_bin` collations) also ignore trailing spaces. `alice`,
 * `Alice`, `álice` and `alice ` are four people, so:
 *
 * - every row a lookup by uploader returns is matched again in PHP with `===` ({@see self::matches()});
 * - an uploader identifier or key with leading or trailing white space, a control, format or line / paragraph separator
 *   character, invalid UTF-8, or more than {@see UploaderKeyResolverInterface::MAX_LENGTH} characters is not stored
 *   ({@see self::storable()}): refused, never trimmed or cut (a trimmed or cut value could name another person);
 * - the columns use a binary NO PAD collation on MySQL / MariaDB ({@see UploaderCollationListener}).
 *
 * Stateless: safe in FrankenPHP worker mode.
 */
final class UploaderIdentity
{
    /**
     * `$value` when it can be stored as an uploader identifier or key, null otherwise (see the class comment).
     */
    public static function storable(?string $value): ?string
    {
        if ($value === null || $value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > UploaderKeyResolverInterface::MAX_LENGTH) {
            return null;
        }
        // Controls, format characters (zero-width, bidi marks), line / paragraph separators anywhere; white space or any
        // other separator at either end.
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]|^[\s\p{Z}]|[\s\p{Z}]$/u', $value) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * Whether a stored file (its `uploaded_by` and `uploaded_by_key`) belongs to the user the GDPR export / erasure was
     * called for, byte for byte: with a key, the files stored with that key plus the files stored without a key under
     * the identifier; without a key, the files stored under the identifier.
     */
    public static function matches(?string $storedIdentifier, ?string $storedKey, string $userIdentifier, ?string $userKey): bool
    {
        if ($userKey === null || $userKey === '') {
            return $storedIdentifier === $userIdentifier;
        }

        return $storedKey === $userKey || ($storedKey === null && $storedIdentifier === $userIdentifier);
    }
}
