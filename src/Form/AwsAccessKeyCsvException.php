<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Form;

use InvalidArgumentException;

/**
 * Refusal of an AWS access-key CSV ({@see AwsAccessKeyCsv}). The message is a fixed English sentence and {@see $reason}
 * one of the `REASON_*` codes (form error key `profile.error.csv_<reason>`): neither ever carries the file's content.
 */
final class AwsAccessKeyCsvException extends InvalidArgumentException
{
    /** The upload failed (PHP upload error, unreadable temporary file). */
    public const REASON_UNREADABLE = 'unreadable';

    /** Empty or above {@see AwsAccessKeyCsv::MAX_BYTES}. */
    public const REASON_SIZE = 'size';

    /** Not UTF-8 text (binary content, control characters). */
    public const REASON_TYPE = 'type';

    /** The header has no `Access key ID` / `Secret access key` column, or a row does not have the header's columns. */
    public const REASON_HEADER = 'header';

    /** Not exactly one data row. */
    public const REASON_ROWS = 'rows';

    /** The access key id is not an AWS one (`AKIA` / `ASIA` + 16). */
    public const REASON_KEY_ID = 'key_id';

    /** The secret access key is not 40 base64 characters. */
    public const REASON_SECRET = 'secret';

    /** A typed access key or secret differs from the file (raised by the form, not the parser). */
    public const REASON_CONFLICT = 'conflict';

    /**
     * @param string $reason one of the `REASON_*` codes
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The AWS access key CSV was refused: ' . $reason . '.');
    }
}
