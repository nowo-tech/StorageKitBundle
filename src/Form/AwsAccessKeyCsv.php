<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Form;

use SensitiveParameter;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function array_map;
use function array_search;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function is_file;
use function preg_match;
use function rtrim;
use function str_getcsv;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * The access key of an AWS IAM access-key CSV (`<user>_accessKeys.csv`, offered by the console when a key is created):
 * header `Access key ID,Secret access key` and one data row; older exports carry more columns (`User name,Password,
 * Access key ID,Secret access key,Console login link`). A UTF-8 BOM, CRLF / CR line ends, quoted cells and trailing
 * blank lines are accepted; header names are matched case-insensitively.
 *
 * Strict by design: at most {@see MAX_BYTES} bytes, UTF-8 text only (no NUL or other control characters — the type is
 * judged by the content, not by the browser's declared type, which is `application/vnd.ms-excel` on many Windows
 * hosts), exactly one data row with the header's column count, key id `^(AKIA|ASIA)[A-Z0-9]{16}$`, secret 40 characters
 * of `[A-Za-z0-9/+=]`. The file is only read: never moved, stored or logged, and a refusal
 * ({@see AwsAccessKeyCsvException}) never repeats its content. The secret is hidden from `var_dump()` / debug output.
 */
final class AwsAccessKeyCsv
{
    /** Largest accepted file (the console's file is about 100 bytes, the old 5-column one about 300). */
    public const MAX_BYTES = 4096;

    /** AWS access key id: long-term (`AKIA`) or temporary (`ASIA`) key. */
    public const KEY_ID_PATTERN = '/^(AKIA|ASIA)[A-Z0-9]{16}$/D';

    /** AWS secret access key: 40 base64 characters. */
    public const SECRET_PATTERN = '/^[A-Za-z0-9\/+=]{40}$/D';

    /** Header of the access key id column (lower case). */
    public const KEY_ID_COLUMN = 'access key id';

    /** Header of the secret access key column (lower case). */
    public const SECRET_COLUMN = 'secret access key';

    private const BOM = "\xEF\xBB\xBF";

    private function __construct(
        public readonly string $keyId,
        #[SensitiveParameter]
        private readonly string $secret,
    ) {
    }

    /**
     * Reads an uploaded file from its temporary path (at most {@see MAX_BYTES} + 1 bytes) and parses it.
     *
     * @throws AwsAccessKeyCsvException
     */
    public static function fromUpload(UploadedFile $file): self
    {
        $path = $file->getPathname();
        if (!$file->isValid() || !is_file($path)) {
            throw new AwsAccessKeyCsvException(AwsAccessKeyCsvException::REASON_UNREADABLE);
        }
        $contents = (string) file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);

        return self::parse($contents);
    }

    /**
     * Parses the content of an access-key CSV.
     *
     * @throws AwsAccessKeyCsvException
     */
    public static function parse(#[SensitiveParameter] string $contents): self
    {
        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            throw new AwsAccessKeyCsvException(AwsAccessKeyCsvException::REASON_SIZE);
        }
        if (str_starts_with($contents, self::BOM)) {
            $contents = substr($contents, strlen(self::BOM));
        }
        // Text only: valid UTF-8 without control characters other than tab and line ends.
        if (preg_match('//u', $contents) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $contents) === 1) {
            throw new AwsAccessKeyCsvException(AwsAccessKeyCsvException::REASON_TYPE);
        }
        $lines = explode("\n", rtrim(str_replace(["\r\n", "\r"], "\n", $contents), "\n \t"));
        if (count($lines) !== 2) {
            throw new AwsAccessKeyCsvException(AwsAccessKeyCsvException::REASON_ROWS);
        }
        $header = array_map(static fn (?string $name): string => strtolower(trim((string) $name)), str_getcsv($lines[0], ',', '"', ''));
        $row    = array_values(array_map(static fn (?string $cell): string => trim((string) $cell), str_getcsv($lines[1], ',', '"', '')));
        $keyAt  = array_search(self::KEY_ID_COLUMN, $header, true);
        $secAt  = array_search(self::SECRET_COLUMN, $header, true);
        if ($keyAt === false || $secAt === false || count($row) !== count($header)) {
            throw new AwsAccessKeyCsvException(AwsAccessKeyCsvException::REASON_HEADER);
        }
        if (preg_match(self::KEY_ID_PATTERN, $row[$keyAt]) !== 1) {
            throw new AwsAccessKeyCsvException(AwsAccessKeyCsvException::REASON_KEY_ID);
        }
        if (preg_match(self::SECRET_PATTERN, $row[$secAt]) !== 1) {
            throw new AwsAccessKeyCsvException(AwsAccessKeyCsvException::REASON_SECRET);
        }

        return new self($row[$keyAt], $row[$secAt]);
    }

    /**
     * The secret access key.
     */
    public function secret(): string
    {
        return $this->secret;
    }

    /**
     * Debug output without the secret.
     *
     * @return array{keyId: string, secret: string}
     */
    public function __debugInfo(): array
    {
        return ['keyId' => $this->keyId, 'secret' => '***'];
    }
}
