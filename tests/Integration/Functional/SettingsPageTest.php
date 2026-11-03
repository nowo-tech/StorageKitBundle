<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Security\AllowAllStorageAccessChecker;
use Nowo\StorageKitBundle\Settings\InstanceStorageSettings;
use Nowo\StorageKitBundle\Storage\StorageProvider;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

use function is_bool;
use function json_encode;

/**
 * The Storage pages and the `storage` Instance Settings section: access, provider-dependent fields (visible_when),
 * write-only secrets encrypted at rest, SSRF validation of every URL-building field, the audit event, *Test
 * connection* (and its redirect back), the configuration-managed instance bucket and scope pages.
 */
final class SettingsPageTest extends KernelTestCase
{
    /**
     * Saves a scope bucket on its Storage page (FormKit form `storage_profile`).
     *
     * @param array<string, bool|string> $fields
     */
    private function save(KernelBrowser $client, array $fields, string $url = '/storage/scopes/acme'): void
    {
        $crawler = $client->request('GET', $url);
        $form    = $crawler->selectButton('Save')->form()->disableValidation();
        foreach ($fields as $name => $value) {
            $field = $form['storage_profile[' . $name . ']'];
            if (is_bool($value)) {
                self::assertInstanceOf(ChoiceFormField::class, $field);
                $value ? $field->tick() : $field->untick();
            } else {
                $form['storage_profile[' . $name . ']'] = $value;
            }
        }
        $client->submit($form);
    }

    /**
     * Saves the instance bucket in the `storage` Instance Settings section.
     *
     * @param array<string, bool|string> $fields
     */
    private function saveSection(KernelBrowser $client, array $fields): Crawler
    {
        $crawler = $client->request('GET', '/admin/settings/sections/storage');
        $form    = $crawler->filter('form[name="settings_section"]')->form();
        $values  = [];
        foreach ($fields as $name => $value) {
            $key = 'settings_section[' . $name . ']' . ($name === 'secret' ? '[value]' : '');
            if (is_bool($value)) {
                $field = $form[$key];
                self::assertInstanceOf(ChoiceFormField::class, $field);
                $value ? $field->tick() : $field->untick();

                continue;
            }
            $values[$key] = $value;
        }

        return $client->submit($form, $values);
    }

    /**
     * @param array<string, bool|string> $override
     *
     * @return array<string, bool|string>
     */
    private function bucket(array $override = []): array
    {
        return $override + [
            'provider'   => StorageProvider::S3_COMPATIBLE,
            'enabled'    => true,
            'endpoint'   => 'http://minio:9000',
            'region'     => 'eu-west-1',
            'bucket'     => 'files',
            'prefix'     => 'app',
            'access_key' => 'test-key',
            'secret'     => 'test-secret-not-real',
            'path_style' => true,
        ];
    }

    /**
     * @param array<string, bool|string> $override
     *
     * @return array<string, bool|string>
     */
    private function section(array $override = []): array
    {
        $bucket = $this->bucket($override);
        unset($bucket['enabled']);

        return $bucket;
    }

    public function testOnlyTheConfiguredRolesOpenThePage(): void
    {
        $kernel = $this->bootKernel();

        $client = $this->client($kernel, null);
        $client->request('GET', '/admin/storage');
        self::assertSame(401, $client->getResponse()->getStatusCode());
        $client = $this->client($kernel, 'alice');
        $client->request('GET', '/admin/storage');
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->request('POST', '/admin/storage/test');
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/storage');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        // The instance bucket is edited in Instance Settings: no form here, a link there.
        self::assertCount(0, $crawler->filter('input[name="storage_profile[bucket]"]'));
        self::assertSame('/admin/settings/sections/storage', $crawler->filter('[data-testid="storage-kit-edit-link"]')->attr('href'));
        self::assertStringContainsString('edited in the Storage settings section', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Uploads off', (string) $client->getResponse()->getContent());
    }

    public function testTheSectionShowsOnlyTheSelectedProvidersFields(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);

        $client->request('GET', '/admin/settings/sections/storage');
        $html = (string) $client->getResponse()->getContent();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('data-controller="visible-when"', $html);
        self::assertStringContainsString('data-nowo-ui-visible-when-field="settings_section[provider]"', $html);
        self::assertStringContainsString('data-nowo-ui-visible-when-in="[&quot;s3_compatible&quot;]"', $html);
        self::assertStringContainsString('data-nowo-ui-visible-when-in="[&quot;aws_s3&quot;,&quot;s3_compatible&quot;]"', $html);
        self::assertStringContainsString('No provider is selected: image uploads are off.', $html);

        // S3-compatible: every field counts.
        $this->saveSection($client, $this->section());
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $settings = $this->service($kernel, InstanceStorageSettings::class);
        self::assertSame('http://minio:9000', $settings->settings()?->endpoint);

        // Amazon S3: the hidden endpoint fields are neither validated nor written, and not used.
        $this->saveSection($client, ['provider' => StorageProvider::AWS_S3, 'endpoint' => 'http://127.0.0.1', 'public_endpoint' => 'nope', 'path_style' => false, 'secret' => '']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->service($kernel, StorageProfiles::class)->reset();
        $connection = $settings->connection();
        self::assertSame([StorageProvider::AWS_S3, 'http://minio:9000', true], [$connection['provider'] ?? null, $connection['endpoint'] ?? null, $connection['path_style'] ?? null]);
        $aws = $settings->settings();
        self::assertNotNull($aws);
        self::assertSame([null, null, false, 'test-secret-not-real'], [$aws->endpoint, $aws->publicEndpoint, $aws->pathStyle, $aws->secret]);

        // Back to S3-compatible: the endpoint is required again.
        $this->saveSection($client, ['provider' => StorageProvider::S3_COMPATIBLE, 'endpoint' => '']);
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // No provider: uploads off, nothing else validated.
        $this->saveSection($client, ['provider' => '', 'bucket' => 'Bad_Bucket']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertNull($settings->provider());
        self::assertNull($settings->settings());
    }

    public function testSavingTheSectionEncryptsTheSecretAndNeverShowsIt(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);
        $this->saveSection($client, $this->section());
        self::assertSame(302, $client->getResponse()->getStatusCode());

        $raw = $this->entityManager($kernel)->getConnection()->fetchAllAssociative('SELECT * FROM instance_setting_value');
        self::assertStringNotContainsString('test-secret-not-real', (string) json_encode($raw));
        $profile = $this->service($kernel, StorageProfiles::class)->activeOf(null);
        self::assertInstanceOf(StorageProfile::class, $profile);
        self::assertTrue($profile->isSettingsBacked());
        self::assertNull($profile->getSecret(), 'the row holds no connection data');
        self::assertSame('files', $this->service($kernel, StorageProfiles::class)->settingsOf($profile)?->bucket);

        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('test-secret-not-real', $html);

        $event = $this->audit($kernel)->events[0];
        self::assertSame(StorageAuditEvent::PROFILE_SAVED, $event->action);
        self::assertSame('admin', $event->actor);
        self::assertNull($event->scope);
        self::assertStringContainsString('secret', (string) $event->details['fields']);
        self::assertSame('form', $event->details['origin']);
        self::assertStringNotContainsString('test-secret-not-real', (string) json_encode($this->audit($kernel)->events));

        $client->request('GET', '/admin/storage');
        self::assertStringContainsString('Uploads on', (string) $client->getResponse()->getContent());

        // A blank secret keeps the stored one.
        $this->saveSection($client, ['prefix' => 'other', 'secret' => '']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $settings = $this->service($kernel, InstanceStorageSettings::class)->settings();
        self::assertSame(['other', 'test-secret-not-real'], [$settings?->prefix, $settings?->secret]);
    }

    /**
     * @return iterable<string, array{array<string, bool|string>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'region host injection' => [['region' => 'x.evil.com/'], 'Use lowercase letters'];
        yield 'region uppercase' => [['region' => 'EU'], 'Use lowercase letters'];
        yield 'endpoint path' => [['endpoint' => 'https://s3.example.com/x'], 'without path'];
        yield 'endpoint credentials' => [['endpoint' => 'https://u:p@s3.example.com'], 'without path'];
        yield 'loopback' => [['endpoint' => 'http://127.0.0.1:9000'], 'not allowed'];
        yield 'decimal loopback' => [['endpoint' => 'http://2130706433'], 'not allowed'];
        yield 'mapped ipv6' => [['endpoint' => 'http://[::ffff:127.0.0.1]'], 'not allowed'];
        yield 'metadata' => [['endpoint' => 'http://169.254.169.254'], 'not allowed'];
        yield 'internal name' => [['endpoint' => 'http://rustfs:9000'], 'not allowed'];
        yield 'public endpoint path' => [['public_endpoint' => 'https://cdn.example.com/x?y'], 'without path'];
        yield 'bucket' => [['bucket' => 'Bad_Bucket'], '3 to 63'];
        yield 'bucket dots' => [['bucket' => 'a..b'], '3 to 63'];
        yield 'prefix traversal' => [['prefix' => 'a/../b'], 'Use letters, digits, slashes'];
        yield 'access key' => [['access_key' => 'a b'], 'must not contain spaces'];
        yield 'secret' => [['secret' => "a\x01b"], 'control characters'];
    }

    /**
     * @param array<string, bool|string> $fields
     */
    #[DataProvider('invalid')]
    public function testEveryUrlBuildingFieldIsValidated(array $fields, string $message): void
    {
        $kernel = $this->bootKernel();

        // Scope bucket (FormKit form).
        $client = $this->client($kernel, 'scopeadmin');
        $this->save($client, $this->bucket($fields));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString($message, (string) $client->getResponse()->getContent());
        self::assertNull($this->service($kernel, StorageProfileRepository::class)->activeOf('acme'));

        // Instance bucket (Instance Settings section, same rules).
        $client = $this->client($kernel);
        $this->saveSection($client, $this->section($fields));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString($message, (string) $client->getResponse()->getContent());
        self::assertNull($this->service($kernel, InstanceStorageSettings::class)->provider());
    }

    public function testAnIncompleteScopeBucketCannotBeTurnedOn(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel, 'scopeadmin');
        $this->save($client, $this->bucket(['bucket' => '', 'secret' => '']));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Set the bucket', (string) $client->getResponse()->getContent());

        $this->save($client, $this->bucket(['endpoint' => '']));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('the endpoint first', (string) $client->getResponse()->getContent());

        $this->save($client, $this->bucket(['provider' => 'ftp']));
        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testTheScopeFormHidesTheEndpointFieldsForAmazonS3(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel, 'scopeadmin');
        $this->save($client, $this->bucket());
        $crawler = $client->request('GET', '/storage/scopes/acme');
        self::assertStringContainsString('data-nowo-ui-visible-when-field="storage_profile[provider]"', (string) $client->getResponse()->getContent());
        self::assertSame('visible-when', $crawler->filter('[data-testid="storage-kit-settings"]')->attr('data-controller'));

        // Amazon S3: a refused endpoint in a hidden row is ignored and the stored one kept (unused).
        $this->save($client, ['provider' => StorageProvider::AWS_S3, 'endpoint' => 'http://10.0.0.5', 'path_style' => false]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->entityManager($kernel)->clear();
        $profile = $this->service($kernel, StorageProfileRepository::class)->activeOf('acme');
        self::assertNotNull($profile);
        self::assertSame([StorageProvider::AWS_S3, 'http://minio:9000', true], [$profile->getProvider(), $profile->getEndpoint(), $profile->isPathStyle()]);
        $settings = $profile->settings(true);
        self::assertSame([null, false], [$settings->endpoint, $settings->pathStyle]);
        self::assertSame('provider', $this->audit($kernel)->events[1]->details['fields']);

        // Unchecked hidden path style (no view value) stays unchecked.
        $this->save($client, ['provider' => StorageProvider::S3_COMPATIBLE, 'path_style' => false]);
        $this->save($client, ['provider' => StorageProvider::AWS_S3]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->entityManager($kernel)->clear();
        self::assertFalse($this->service($kernel, StorageProfileRepository::class)->activeOf('acme')?->isPathStyle());
    }

    public function testAnUnguardedInstanceProfileAcceptsPrivateEndpoints(): void
    {
        $kernel = $this->bootKernel(['endpoints' => ['guard_instance_profile' => false, 'resolve_dns' => false]]);
        $client = $this->client($kernel);
        $this->saveSection($client, $this->section(['endpoint' => 'http://10.0.0.5:9000']));

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('http://10.0.0.5:9000', $this->service($kernel, InstanceStorageSettings::class)->settings()?->endpoint);
    }

    public function testTestConnection(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);

        $client->request('POST', '/admin/storage/test');
        $client->followRedirect();
        self::assertStringContainsString('Choose a provider and save the Storage settings before testing them.', (string) $client->getResponse()->getContent());

        $crawler = $this->saveSection($client, $this->section());
        $crawler = $client->followRedirect();
        // From the section page: back to the section page.
        $client->submit($crawler->filter('[data-testid="storage-kit-section-test"]')->closest('form')?->form() ?? self::fail('no test form'));
        self::assertSame('/admin/settings/sections/storage', $client->getResponse()->headers->get('Location'));
        $client->followRedirect();
        self::assertStringContainsString('The connection works', (string) $client->getResponse()->getContent());
        self::assertSame([StorageAuditEvent::PROFILE_SAVED, StorageAuditEvent::PROFILE_TESTED], $this->audit($kernel)->actions());

        // From the storage page: back to the storage page.
        $this->buckets($kernel)->failWrites = true;
        $crawler                            = $client->request('GET', '/admin/storage');
        $client->submit($crawler->filter('[data-testid="storage-kit-test"]')->closest('form')?->form() ?? self::fail('no test form'));
        self::assertSame('/admin/storage', $client->getResponse()->headers->get('Location'));
        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('The test failed at the write step: unexpected error', $html);
        self::assertStringContainsString('Failed', $html);
        $client->request('GET', '/admin/settings/sections/storage');
        self::assertStringContainsString('The last connection test failed.', (string) $client->getResponse()->getContent());

        // Saving the section forgets the old result.
        $this->buckets($kernel)->failWrites = false;
        $this->saveSection($client, ['prefix' => 'other']);
        $client->request('GET', '/admin/settings/sections/storage');
        self::assertStringNotContainsString('The last connection test failed.', (string) $client->getResponse()->getContent());

        // A forged token is refused; any other "from" value goes back to the storage page.
        $client->request('POST', '/admin/storage/test?from=https://evil.example', ['csrf_only' => ['_token' => 'forged']]);
        self::assertSame('/admin/storage', $client->getResponse()->headers->get('Location'));
        $client->followRedirect();
        self::assertStringContainsString('Choose a provider and save the Storage settings', (string) $client->getResponse()->getContent());
    }

    public function testAnIncompleteSectionSaysSo(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);
        $this->service($kernel, InstanceSettingsInterface::class)->save('storage', ['provider' => StorageProvider::AWS_S3, 'region' => 'eu-west-1', 'bucket' => 'files', 'access_key' => 'k', 'secret' => 's']);
        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE instance_setting_value SET value = '\"BAD\"' WHERE name = 'region'");
        $client->request('GET', '/admin/settings/sections/storage');

        self::assertStringContainsString('is missing a field', (string) $client->getResponse()->getContent());
    }

    public function testATestOfAnUnusableProfileSaysSo(): void
    {
        $kernel  = $this->bootKernel();
        $client  = $this->client($kernel);
        $profile = $this->profile($kernel);
        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE storage_kit_profile SET region = 'BAD'");
        $this->entityManager($kernel)->clear();
        $crawler = $client->request('GET', '/admin/storage');
        $client->submit($crawler->filter('[data-testid="storage-kit-test"]')->closest('form')?->form() ?? self::fail('no test form'));
        $client->followRedirect();

        self::assertStringContainsString('incomplete or invalid', (string) $client->getResponse()->getContent());
        self::assertNotNull($profile->getId());
    }

    public function testAConfigurationManagedInstanceBucketIsReadOnly(): void
    {
        $kernel = $this->bootKernel([
            'profiles'         => ['main' => ['dsn' => 's3://test-key:test-secret-not-real@minio:9000/main?tls=0&path_style=1']],
            'instance_profile' => 'main',
        ]);
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/storage');
        $html    = (string) $client->getResponse()->getContent();

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('input[name="storage_profile[bucket]"]'));
        self::assertStringContainsString('comes from the application configuration', $html);
        self::assertStringContainsString('Redirect to the bucket', $html);
        self::assertStringNotContainsString('test-secret-not-real', $html);
        self::assertStringContainsString('Uploads on', $html);

        $client->submit($crawler->filter('[data-testid="storage-kit-test"]')->closest('form')?->form() ?? self::fail('no test form'));
        $client->followRedirect();
        self::assertStringContainsString('The connection works', (string) $client->getResponse()->getContent());
    }

    public function testAnUnusableConfigurationBucketIsShown(): void
    {
        $kernel = $this->bootKernel([
            'profiles'         => ['main' => ['dsn' => 's3://k:s@aws/main?region=BAD']],
            'instance_profile' => 'main',
        ]);
        $client = $this->client($kernel);
        $client->request('GET', '/admin/storage');

        self::assertStringContainsString('not usable: check the configuration', (string) $client->getResponse()->getContent());
    }

    public function testScopePagesAreOnlyForTheirManagers(): void
    {
        $kernel = $this->bootKernel();
        foreach (['alice', 'admin'] as $user) {
            $client = $this->client($kernel, $user);
            $client->request('GET', '/storage/scopes/acme');
            self::assertSame(404, $client->getResponse()->getStatusCode(), $user);
        }
        $client = $this->client($kernel, 'scopeadmin');
        $client->request('GET', '/storage/scopes/other');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->request('POST', '/storage/scopes/other/test');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->request('GET', '/admin/storage');
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $this->save($client, $this->bucket(['endpoint' => 'http://minio:9000']), '/storage/scopes/acme');
        self::assertSame('/storage/scopes/acme', $client->getResponse()->headers->get('Location'));
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Storage of acme', (string) $client->getResponse()->getContent());
        self::assertSame('acme', $this->service($kernel, StorageProfileRepository::class)->activeOf('acme')?->getScope());
        self::assertSame('acme', $this->audit($kernel)->events[0]->scope);

        $client->submit($crawler->filter('[data-testid="storage-kit-test"]')->closest('form')?->form() ?? self::fail('no test form'));
        self::assertSame('/storage/scopes/acme', $client->getResponse()->headers->get('Location'));

        // Scope buckets are always guarded.
        $this->save($client, $this->bucket(['endpoint' => 'http://10.0.0.5']), '/storage/scopes/acme');
        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testWithoutScopesTheScopePagesDoNotExist(): void
    {
        $kernel = $this->bootKernel(['scopes' => ['enabled' => false]]);
        $client = $this->client($kernel, 'scopeadmin');
        $client->request('GET', '/storage/scopes/acme');

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testTheDemoSwitchOpensThePagesWithoutLogin(): void
    {
        $kernel = $this->bootKernel(['security' => ['allow_unauthenticated' => true]], debug: true);
        $client = $this->client($kernel, null);
        $client->request('GET', '/admin/storage');

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testACustomAccessCheckerAndTemplate(): void
    {
        $kernel = $this->bootKernel([
            'security' => ['access_checker' => 'test.allow_all', 'access_roles' => []],
            'web_ui'   => ['templates' => ['settings' => '@NowoStorageKitBundle/settings/edit.html.twig'], 'layout_template' => null],
        ], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set('test.allow_all', AllowAllStorageAccessChecker::class);
        });
        $client = $this->client($kernel, 'alice');
        $client->request('GET', '/admin/storage');

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }
}
