<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Form;

use Nowo\StorageKitBundle\Form\AwsAccessKeyCsv;
use Nowo\StorageKitBundle\Form\AwsAccessKeyCsvException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function file_put_contents;
use function print_r;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_OK;

/**
 * The AWS IAM access-key CSV parser: the console's 2-column file, BOM / CRLF, the old 5-column export, and every refusal.
 */
final class AwsAccessKeyCsvTest extends TestCase
{
    /** AWS's documented example key (not a real credential). */
    public const KEY_ID = 'AKIAIOSFODNN7EXAMPLE';

    /** AWS's documented example secret (not a real credential). */
    public const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    /**
     * @return iterable<string, array{0: string, 1?: string}>
     */
    public static function validFiles(): iterable
    {
        yield 'console file' => ["Access key ID,Secret access key\n" . self::KEY_ID . ',' . self::SECRET . "\n"];
        yield 'BOM and CRLF' => ["\xEF\xBB\xBFAccess key ID,Secret access key\r\n" . self::KEY_ID . ',' . self::SECRET . "\r\n"];
        yield 'no final line end' => ['Access key ID,Secret access key' . "\n" . self::KEY_ID . ',' . self::SECRET];
        yield 'old CR line ends' => ["Access key ID,Secret access key\r" . self::KEY_ID . ',' . self::SECRET . "\r\r"];
        yield 'old 5-column export' => ["User name,Password,Access key ID,Secret access key,Console login link\r\nbob,," . self::KEY_ID . ',' . self::SECRET . ",https://123456789012.signin.aws.amazon.com/console\r\n\r\n"];
        yield 'quoted cells, other case, spaces' => ['"ACCESS KEY ID" , "secret ACCESS key"' . "\n" . '"' . self::KEY_ID . '", ' . self::SECRET . " \n"];
        yield 'secret column first' => ["Secret access key,Access key ID\n" . self::SECRET . ',' . self::KEY_ID . "\n"];
        yield 'temporary key' => ["Access key ID,Secret access key\nASIAIOSFODNN7EXAMPLE," . self::SECRET . "\n", 'ASIAIOSFODNN7EXAMPLE'];
    }

    #[DataProvider('validFiles')]
    public function testAValidFileGivesTheKey(string $contents, string $keyId = self::KEY_ID): void
    {
        $csv = AwsAccessKeyCsv::parse($contents);

        self::assertSame($keyId, $csv->keyId);
        self::assertSame(self::SECRET, $csv->secret());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedFiles(): iterable
    {
        $row = self::KEY_ID . ',' . self::SECRET;

        yield 'empty' => ['', AwsAccessKeyCsvException::REASON_SIZE];
        yield 'oversize' => ["Access key ID,Secret access key\n" . $row . "\n" . str_repeat(' ', AwsAccessKeyCsv::MAX_BYTES), AwsAccessKeyCsvException::REASON_SIZE];
        yield 'binary (NUL bytes)' => ["PK\x03\x04\x00\x00Access key ID,Secret access key\n" . $row, AwsAccessKeyCsvException::REASON_TYPE];
        yield 'not UTF-8' => ["Access key ID,Secret access key\n\xFF\xFE" . $row, AwsAccessKeyCsvException::REASON_TYPE];
        yield 'escape character' => ["Access key ID,Secret access key\n\x1B[31m" . $row, AwsAccessKeyCsvException::REASON_TYPE];
        yield 'header only' => ["Access key ID,Secret access key\r\n\r\n", AwsAccessKeyCsvException::REASON_ROWS];
        yield 'two rows' => ["Access key ID,Secret access key\n" . $row . "\n" . $row . "\n", AwsAccessKeyCsvException::REASON_ROWS];
        yield 'blank line before the row' => ["Access key ID,Secret access key\n\n" . $row, AwsAccessKeyCsvException::REASON_ROWS];
        yield 'no secret column' => ["Access key ID,Secret\n" . $row, AwsAccessKeyCsvException::REASON_HEADER];
        yield 'no key column' => ["Key,Secret access key\n" . $row, AwsAccessKeyCsvException::REASON_HEADER];
        yield 'no header' => [$row . "\n" . $row, AwsAccessKeyCsvException::REASON_HEADER];
        yield 'row with fewer columns' => ["User name,Access key ID,Secret access key\n" . $row, AwsAccessKeyCsvException::REASON_HEADER];
        yield 'lowercase key id' => ["Access key ID,Secret access key\nakiaiosfodnn7example," . self::SECRET, AwsAccessKeyCsvException::REASON_KEY_ID];
        yield 'other prefix' => ["Access key ID,Secret access key\nAIDAIOSFODNN7EXAMPLE," . self::SECRET, AwsAccessKeyCsvException::REASON_KEY_ID];
        yield 'short key id' => ["Access key ID,Secret access key\nAKIAIOSFODNN7EXAMPL," . self::SECRET, AwsAccessKeyCsvException::REASON_KEY_ID];
        yield 'empty key id' => ["Access key ID,Secret access key\n," . self::SECRET, AwsAccessKeyCsvException::REASON_KEY_ID];
        yield 'short secret' => ["Access key ID,Secret access key\n" . self::KEY_ID . ',wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKE', AwsAccessKeyCsvException::REASON_SECRET];
        yield 'secret with a space' => ["Access key ID,Secret access key\n" . self::KEY_ID . ',"wJalrXUtnFEMI K7MDENG/bPxRfiCYEXAMPLEKEY"', AwsAccessKeyCsvException::REASON_SECRET];
        yield 'secret with a quote' => ["Access key ID,Secret access key\n" . self::KEY_ID . ',wJalrXUtnFEMI\'K7MDENG/bPxRfiCYEXAMPLEKEY', AwsAccessKeyCsvException::REASON_SECRET];
    }

    #[DataProvider('refusedFiles')]
    public function testARefusalNamesTheReasonButNeverTheContent(string $contents, string $reason): void
    {
        try {
            AwsAccessKeyCsv::parse($contents);
            self::fail('The file was accepted.');
        } catch (AwsAccessKeyCsvException $refused) {
            self::assertSame($reason, $refused->reason);
            self::assertSame('The AWS access key CSV was refused: ' . $reason . '.', $refused->getMessage());
            self::assertStringNotContainsString('EXAMPLE', $refused->getMessage());
        }
    }

    public function testTheSecretIsHiddenFromDebugOutput(): void
    {
        $csv    = AwsAccessKeyCsv::parse("Access key ID,Secret access key\n" . self::KEY_ID . ',' . self::SECRET);
        $output = print_r($csv, true);

        self::assertStringContainsString(self::KEY_ID, $output);
        self::assertStringNotContainsString(self::SECRET, $output);
        self::assertSame(['keyId' => self::KEY_ID, 'secret' => '***'], $csv->__debugInfo());
    }

    public function testAnUploadIsReadFromItsTemporaryFile(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "Access key ID,Secret access key\n" . self::KEY_ID . ',' . self::SECRET . "\n");

        $csv = AwsAccessKeyCsv::fromUpload(new UploadedFile($path, 'bob_accessKeys.csv', 'application/vnd.ms-excel', UPLOAD_ERR_OK, true));
        self::assertSame(self::KEY_ID, $csv->keyId);

        // Too large on disk: only MAX_BYTES + 1 bytes are read.
        file_put_contents($path, str_repeat('A', AwsAccessKeyCsv::MAX_BYTES * 4));
        $this->assertRefused(new UploadedFile($path, 'big.csv', 'text/csv', UPLOAD_ERR_OK, true), AwsAccessKeyCsvException::REASON_SIZE);

        // A failed upload, and a temporary file that is gone.
        $this->assertRefused(new UploadedFile($path, 'big.csv', 'text/csv', UPLOAD_ERR_INI_SIZE, true), AwsAccessKeyCsvException::REASON_UNREADABLE);
        $gone = new UploadedFile($path, 'gone.csv', 'text/csv', UPLOAD_ERR_OK, true);
        unlink($path);
        $this->assertRefused($gone, AwsAccessKeyCsvException::REASON_UNREADABLE);
    }

    private function assertRefused(UploadedFile $file, string $reason): void
    {
        try {
            AwsAccessKeyCsv::fromUpload($file);
            self::fail('The upload was accepted.');
        } catch (AwsAccessKeyCsvException $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}
