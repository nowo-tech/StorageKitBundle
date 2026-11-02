<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Functional;

use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\StorageKitBundle\Security\AllowAllStorageAccessChecker;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

use function is_bool;
use function json_encode;

/**
 * The Storage page: access, the FormKit bucket form (secret write-only, encrypted at rest), SSRF validation of every
 * URL-building field, the audit event, *Test connection*, the configuration-managed instance bucket and scope pages.
 */
final class SettingsPageTest extends KernelTestCase
{
    /**
     * @param array<string, bool|string> $fields
     */
    private function save(KernelBrowser $client, array $fields, string $url = '/admin/storage'): void
    {
        $crawler = $client->request('GET', $url);
        $form    = $crawler->selectButton('Save')->form();
        $values  = [];
        foreach ($fields as $name => $value) {
            $values['storage_profile[' . $name . ']'] = $value;
        }
        foreach ($values as $name => $value) {
            $field = $form[$name];
            if (is_bool($value)) {
                self::assertInstanceOf(ChoiceFormField::class, $field);
                $value ? $field->tick() : $field->untick();
            } else {
                $form[$name] = $value;
            }
        }
        $client->submit($form);
    }

    /**
     * @param array<string, bool|string> $override
     *
     * @return array<string, bool|string>
     */
    private function bucket(array $override = []): array
    {
        return $override + [
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
        $client = $this->client($kernel);
        $client->request('GET', '/admin/storage');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testSavingEncryptsTheSecretAndNeverShowsIt(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);
        $this->save($client, $this->bucket());
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/admin/storage', $client->getResponse()->headers->get('Location'));

        $profile = $this->service($kernel, StorageProfileRepository::class)->activeOf(null);
        self::assertInstanceOf(StorageProfile::class, $profile);
        self::assertSame('test-secret-not-real', $profile->getSecret());
        $raw = $this->entityManager($kernel)->getConnection()->fetchOne('SELECT secret FROM storage_kit_profile');
        self::assertIsString($raw);
        self::assertStringNotContainsString('test-secret-not-real', $raw);

        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('The storage settings were saved.', $html);
        self::assertStringContainsString('Uploads on', $html);
        self::assertStringNotContainsString('test-secret-not-real', $html);
        self::assertStringContainsString('A secret key is saved', $html);

        $event = $this->audit($kernel)->events[0];
        self::assertSame(StorageAuditEvent::PROFILE_SAVED, $event->action);
        self::assertSame('admin', $event->actor);
        self::assertNull($event->scope);
        self::assertSame('enabled,endpoint,region,bucket,prefix,access_key,path_style,secret', $event->details['fields']);
        self::assertStringNotContainsString('test-secret-not-real', (string) json_encode($this->audit($kernel)->events));

        // A blank secret keeps the stored one; only the changed field is audited.
        $this->save($client, ['prefix' => 'other', 'secret' => '']);
        $this->entityManager($kernel)->clear();
        $profile = $this->service($kernel, StorageProfileRepository::class)->activeOf(null);
        self::assertSame(['other', 'test-secret-not-real'], [$profile?->getPrefix(), $profile?->getSecret()]);
        self::assertSame('prefix', $this->audit($kernel)->events[1]->details['fields']);
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
        yield 'incomplete' => [['bucket' => '', 'secret' => ''], 'Set the bucket'];
    }

    /**
     * @param array<string, bool|string> $fields
     */
    #[DataProvider('invalid')]
    public function testEveryUrlBuildingFieldIsValidated(array $fields, string $message): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);
        $this->save($client, $this->bucket($fields));

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString($message, (string) $client->getResponse()->getContent());
        self::assertNull($this->service($kernel, StorageProfileRepository::class)->activeOf(null));
    }

    public function testAnUnguardedInstanceProfileAcceptsPrivateEndpoints(): void
    {
        $kernel = $this->bootKernel(['endpoints' => ['guard_instance_profile' => false, 'resolve_dns' => false]]);
        $client = $this->client($kernel);
        $this->save($client, $this->bucket(['endpoint' => 'http://10.0.0.5:9000']));

        self::assertSame(302, $client->getResponse()->getStatusCode());
    }

    public function testTestConnection(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);

        $client->request('POST', '/admin/storage/test');
        $client->followRedirect();
        self::assertStringContainsString('Save the bucket before testing it.', (string) $client->getResponse()->getContent());

        $this->save($client, $this->bucket());
        $crawler = $client->request('GET', '/admin/storage');
        $client->submit($crawler->filter('[data-testid="storage-kit-test"]')->closest('form')?->form() ?? self::fail('no test form'));
        self::assertSame('/admin/storage', $client->getResponse()->headers->get('Location'));
        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('The connection works', $html);
        self::assertStringContainsString('Passed', $html);
        self::assertSame([StorageAuditEvent::PROFILE_SAVED, StorageAuditEvent::PROFILE_TESTED], $this->audit($kernel)->actions());

        $this->buckets($kernel)->failWrites = true;
        $crawler                            = $client->request('GET', '/admin/storage');
        $client->submit($crawler->filter('[data-testid="storage-kit-test"]')->closest('form')?->form() ?? self::fail('no test form'));
        $client->followRedirect();
        self::assertStringContainsString('The test failed at the write step: unexpected error', (string) $client->getResponse()->getContent());

        // A forged token is refused.
        $client->request('POST', '/admin/storage/test', ['csrf_only' => ['_token' => 'forged']]);
        $client->followRedirect();
        self::assertStringContainsString('Save the bucket before testing it.', (string) $client->getResponse()->getContent());
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
