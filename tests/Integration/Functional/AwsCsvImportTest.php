<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Functional;

use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Settings\InstanceStorageSettings;
use Nowo\StorageKitBundle\Storage\StorageProvider;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Unit\Form\AwsAccessKeyCsvTest;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;

use function file_put_contents;
use function is_bool;
use function json_encode;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * *Import from AWS CSV* on the bucket forms (scope Storage page, Move target, `storage` Instance Settings section): the
 * file fills the access key and the write-only secret, is never stored or rendered back, refusals are form errors on
 * its row, a typed value that differs is a conflict, and a file sent with another provider is ignored.
 */
final class AwsCsvImportTest extends KernelTestCase
{
    private const KEY_ID = AwsAccessKeyCsvTest::KEY_ID;

    private const SECRET = AwsAccessKeyCsvTest::SECRET;

    private const CONSOLE_CSV = "\xEF\xBB\xBFAccess key ID,Secret access key\r\n" . self::KEY_ID . ',' . self::SECRET . "\r\n";

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
        parent::tearDown();
    }

    private function csv(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'awscsv');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    /**
     * Submits a bucket form with an optional CSV upload.
     *
     * @param array<string, bool|string> $fields
     */
    private function submit(KernelBrowser $client, string $url, string $formName, string $button, array $fields, ?string $csv): void
    {
        $crawler = $client->request('GET', $url);
        $form    = $crawler->filter('form[name="' . $formName . '"]')->count() > 0
            ? $crawler->filter('form[name="' . $formName . '"]')->form()
            : $crawler->selectButton($button)->form();
        $form->disableValidation();
        foreach ($fields as $name => $value) {
            $key = $formName . '[' . $name . ']';
            if (is_bool($value)) {
                $field = $form[$key];
                self::assertInstanceOf(ChoiceFormField::class, $field);
                $value ? $field->tick() : $field->untick();

                continue;
            }
            $form[$key] = $value;
        }
        if ($csv !== null) {
            $file = $form[$formName . '[access_key_csv]'];
            self::assertInstanceOf(FileFormField::class, $file);
            $file->upload($this->csv($csv));
        }
        $client->submit($form);
    }

    /**
     * @param array<string, bool|string> $fields
     */
    private function saveScope(KernelBrowser $client, array $fields, ?string $csv): void
    {
        $this->submit($client, '/storage/scopes/acme', 'storage_profile', 'Save', $fields, $csv);
    }

    /**
     * @param array<string, bool|string> $override
     *
     * @return array<string, bool|string>
     */
    private function aws(array $override = []): array
    {
        return $override + [
            'provider'   => StorageProvider::AWS_S3,
            'enabled'    => true,
            'region'     => 'eu-west-1',
            'bucket'     => 'files',
            'prefix'     => '',
            'access_key' => '',
            'secret'     => '',
        ];
    }

    public function testTheRowIsAnOptionalFileInputForAmazonS3Only(): void
    {
        $kernel  = $this->bootKernel();
        $client  = $this->client($kernel, 'scopeadmin');
        $crawler = $client->request('GET', '/storage/scopes/acme');

        self::assertSame('multipart/form-data', $crawler->filter('form[name="storage_profile"]')->attr('enctype'));
        $input = $crawler->filter('input[name="storage_profile[access_key_csv]"]');
        self::assertSame(['file', '.csv,text/csv,text/plain', null, 'storage-kit-access-key-csv'], [$input->attr('type'), $input->attr('accept'), $input->attr('required'), $input->attr('data-testid')]);
        $row = $input->closest('[data-nowo-ui-visible-when-field]');
        self::assertNotNull($row);
        self::assertSame('storage_profile[provider]', $row->attr('data-nowo-ui-visible-when-field'));
        self::assertStringContainsString('aws_s3', (string) $row->attr('data-nowo-ui-visible-when-in'));
        self::assertStringContainsString('Import from AWS CSV', (string) $client->getResponse()->getContent());
        // No inline script or style comes with it (CSP).
        self::assertStringNotContainsString('<script>', (string) $client->getResponse()->getContent());

    }

    public function testTheConsoleFileFillsTheKeyAndTheSecretAndIsNeverShownOrStored(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel, 'scopeadmin');

        $this->saveScope($client, $this->aws(), self::CONSOLE_CSV);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->entityManager($kernel)->clear();
        $profile = $this->service($kernel, StorageProfileRepository::class)->activeOf('acme');
        self::assertNotNull($profile);
        self::assertSame([self::KEY_ID, self::SECRET, true], [$profile->getAccessKey(), $profile->getSecret(), $profile->isEnabled()]);
        self::assertSame('provider,enabled,region,bucket,access_key,secret', $this->audit($kernel)->events[0]->details['fields']);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($this->audit($kernel)->events));
        // Stored encrypted (DoctrineEncrypt), never in clear.
        $raw = $this->entityManager($kernel)->getConnection()->fetchAllAssociative('SELECT * FROM storage_kit_profile');
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($raw));

        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString(self::SECRET, $html);
        self::assertStringContainsString('A secret key is saved', $html);
    }

    public function testANewFileReplacesTheSavedKey(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel, 'scopeadmin');
        $this->saveScope($client, $this->aws(['access_key' => 'AKIAOLDOLDOLDOLDOLD1', 'secret' => 'old-secret-not-real']), null);
        self::assertSame(302, $client->getResponse()->getStatusCode());

        // The access key row is pre-filled with the saved key: left as is, the file replaces it (key rotation).
        $this->submit($client, '/storage/scopes/acme', 'storage_profile', 'Save', [], self::CONSOLE_CSV);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->entityManager($kernel)->clear();
        $profile = $this->service($kernel, StorageProfileRepository::class)->activeOf('acme');
        self::assertSame([self::KEY_ID, self::SECRET], [$profile?->getAccessKey(), $profile?->getSecret()]);
    }

    public function testTheOldFiveColumnExportIsAccepted(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel, 'scopeadmin');
        $this->saveScope($client, $this->aws(), "User name,Password,Access key ID,Secret access key,Console login link\nbob,," . self::KEY_ID . ',' . self::SECRET . ",https://123456789012.signin.aws.amazon.com/console\n");

        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->entityManager($kernel)->clear();
        self::assertSame(self::KEY_ID, $this->service($kernel, StorageProfileRepository::class)->activeOf('acme')?->getAccessKey());
    }

    public function testTypedValuesThatDifferFromTheFileAreAConflict(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel, 'scopeadmin');

        foreach ([['access_key' => 'AKIAOTHEROTHEROTHER1'], ['secret' => 'another-secret-typed']] as $typed) {
            $this->saveScope($client, $this->aws($typed), self::CONSOLE_CSV);
            self::assertSame(422, $client->getResponse()->getStatusCode());
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('differs from the CSV file', $html);
            self::assertStringNotContainsString(self::SECRET, $html);
            self::assertStringNotContainsString(self::KEY_ID, $html);
        }
        $this->entityManager($kernel)->clear();
        self::assertNull($this->service($kernel, StorageProfileRepository::class)->activeOf('acme'));

        // The same values typed and in the file are fine.
        $this->saveScope($client, $this->aws(['access_key' => ' ' . self::KEY_ID, 'secret' => self::SECRET]), self::CONSOLE_CSV);
        self::assertSame(302, $client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'header' => ["Key,Secret\n" . self::KEY_ID . ',' . self::SECRET, 'it needs the columns'];
        yield 'two rows' => ["Access key ID,Secret access key\n" . self::KEY_ID . ',' . self::SECRET . "\n" . self::KEY_ID . ',' . self::SECRET, 'exactly one access key'];
        yield 'oversize' => [str_repeat('A', 5000), 'at most 4 KB'];
        yield 'binary' => ["\x89PNG\r\n\x1A\n\x00\x00\x00\rIHDR", 'not a text CSV file'];
        yield 'temporary STS key' => ["Access key ID,Secret access key\nASIAIOSFODNN7EXAMPLE," . self::SECRET, 'temporary AWS STS key'];
        yield 'key id' => ["Access key ID,Secret access key\nnot-a-key," . self::SECRET, 'valid AWS access key ID'];
        yield 'secret' => ["Access key ID,Secret access key\n" . self::KEY_ID . ',short', 'valid AWS secret access key'];
    }

    #[DataProvider('refused')]
    public function testARefusedFileIsAFormErrorOnItsRow(string $contents, string $message): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel, 'scopeadmin');

        $this->saveScope($client, $this->aws(['access_key' => 'AKIATYPEDTYPEDTYPED1']), $contents);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($message, $html);
        self::assertStringNotContainsString(self::SECRET, $html);
        $this->entityManager($kernel)->clear();
        self::assertNull($this->service($kernel, StorageProfileRepository::class)->activeOf('acme'));
    }

    public function testAFileSentWithAnotherProviderIsIgnored(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel, 'scopeadmin');
        $this->saveScope($client, [
            'provider'   => StorageProvider::S3_COMPATIBLE,
            'enabled'    => true,
            'endpoint'   => 'http://minio:9000',
            'bucket'     => 'files',
            'access_key' => 'minio-key',
            'secret'     => 'minio-secret-not-real',
        ], self::CONSOLE_CSV);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->entityManager($kernel)->clear();
        $profile = $this->service($kernel, StorageProfileRepository::class)->activeOf('acme');
        self::assertSame(['minio-key', 'minio-secret-not-real'], [$profile?->getAccessKey(), $profile?->getSecret()]);
    }

    public function testTheMoveTargetAcceptsTheFile(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/storage/move');
        self::assertCount(1, $crawler->filter('input[type="file"][name="storage_target[access_key_csv]"][data-testid="storage-kit-access-key-csv"]'));
        $this->submit($client, '/admin/storage/move', 'storage_target', 'Save the new bucket', [
            'provider' => StorageProvider::AWS_S3,
            'region'   => 'eu-west-1',
            'bucket'   => 'new-files',
        ], self::CONSOLE_CSV);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->entityManager($kernel)->clear();
        $target = $this->service($kernel, StorageProfileRepository::class)->targetOf(null);
        self::assertSame([self::KEY_ID, self::SECRET], [$target?->getAccessKey(), $target?->getSecret()]);
    }

    public function testTheStorageSectionAcceptsTheFile(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);
        $this->submit($client, '/admin/settings/sections/storage', 'settings_section', 'Save', [
            'provider' => StorageProvider::AWS_S3,
            'region'   => 'eu-west-1',
            'bucket'   => 'files',
        ], self::CONSOLE_CSV);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        $settings = $this->service($kernel, InstanceStorageSettings::class)->settings();
        self::assertSame([self::KEY_ID, self::SECRET], [$settings?->accessKey, $settings?->secret()]);
        $raw = $this->entityManager($kernel)->getConnection()->fetchAllAssociative('SELECT * FROM instance_setting_value');
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($raw));
        self::assertNotNull($this->service($kernel, StorageProfiles::class)->activeOf(null));

        // A refused file is an error on the section's row; a typed secret that differs is a conflict.
        $this->submit($client, '/admin/settings/sections/storage', 'settings_section', 'Save', [], "Access key ID\n" . self::KEY_ID);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('it needs the columns', (string) $client->getResponse()->getContent());
        $this->submit($client, '/admin/settings/sections/storage', 'settings_section', 'Save', ['secret][value' => 'typed-secret'], self::CONSOLE_CSV);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('differs from the CSV file', (string) $client->getResponse()->getContent());
        self::assertCount(1, $client->getCrawler()->filter('form[name="settings_section"] input[data-testid="storage-kit-access-key-csv"]'));
    }
}
