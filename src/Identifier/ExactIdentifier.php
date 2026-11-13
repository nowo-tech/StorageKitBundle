<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Identifier;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\StorageKitBundle\Doctrine\UploaderCollationListener;

use function is_int;
use function is_string;
use function mb_check_encoding;
use function mb_strlen;
use function preg_match;

/**
 * Byte-for-byte rules for the string identifiers the bundle stores and matches (REQ-SEC-006): the uploader identity
 * (`uploaded_by`, `uploaded_by_key`, 1.5.2), the file context id (`context_id`), the usage owner (`owner`), the
 * reference source id (`source_id`) and the storage scope (`scope`), 1.5.3.
 *
 * MySQL and MariaDB compare strings with the column collation: the host defaults fold case and accents and the PAD
 * SPACE ones ignore trailing spaces, so `bob`, `Bob`, `bób` and `bob ` are one value to the database. The bundle
 *
 * - refuses an unusable identifier where it enters ({@see self::usable()}): never trimmed or cut;
 * - re-checks every row an SQL lookup returns with `===`, and filters exactly in SQL before a `LIMIT`
 *   ({@see self::sql()});
 * - gives the columns a binary NO PAD collation on MySQL / MariaDB ({@see UploaderCollationListener}).
 *
 * Stateless: safe in FrankenPHP worker mode.
 *
 * @internal not covered by the backward compatibility promise
 */
final class ExactIdentifier
{
    /**
     * `$value` when it can be stored as an identifier of at most `$maxLength` characters, null otherwise: empty,
     * invalid UTF-8, too long, white space or any other separator (NBSP included) at either end, or a control, format
     * (zero-width, BOM, bidi marks), line or paragraph separator character anywhere.
     */
    public static function usable(?string $value, int $maxLength): ?string
    {
        if ($value === null || $value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maxLength) {
            return null;
        }
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]|^[\s\p{Z}]|[\s\p{Z}]$/Du', $value) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * An SQL expression of `$column` that compares byte for byte whatever the column collation: MySQL / MariaDB
     * `CAST(column AS BINARY)`, SQLite `column COLLATE BINARY` (a `NOCASE` / `RTRIM` column folds otherwise),
     * PostgreSQL the column itself (deterministic collations compare exactly). Use it next to the plain comparison
     * (`column = ? AND <exact> = ?`) so the column index still narrows the rows.
     */
    public static function sql(AbstractPlatform $platform, string $column): string
    {
        return match (true) {
            $platform instanceof AbstractMySQLPlatform => 'CAST(' . $column . ' AS BINARY)',
            $platform instanceof SQLitePlatform        => $column . ' COLLATE BINARY',
            default                                    => $column,
        };
    }

    /**
     * Whether a value read from the database is exactly `$expected` (a driver may return a scalar of another type).
     */
    public static function same(mixed $stored, string $expected): bool
    {
        return (is_string($stored) || is_int($stored)) && (string) $stored === $expected;
    }
}
