<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\StorageKitBundle\EventListener\InstanceSettingsListener;
use Nowo\StorageKitBundle\Settings\InstanceStorageSettings;
use Nowo\StorageKitBundle\Storage\StorageProvider;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Edge cases of the `storage` Instance Settings section: no admin pages (no actions), no Instance Settings routes
 * (no edit link), an S3-compatible provider without an endpoint, and changes of other sections.
 */
final class InstanceSettingsSectionTest extends KernelTestCase
{
    public function testWithoutTheAdminPagesTheSectionHasNoActions(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['enabled' => false]]);
        $client = $this->client($kernel);
        $client->request('GET', '/admin/settings/sections/storage');
        $html = (string) $client->getResponse()->getContent();

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('settings_section[provider]', $html);
        self::assertStringNotContainsString('storage-kit-section-test', $html);
    }

    public function testWithoutTheInstanceSettingsRoutesTheStoragePageHasNoEditLink(): void
    {
        $kernel = $this->bootKernel(routes: static function (RoutingConfigurator $routes): void {
            $routes->import('.', 'nowo_storage_kit');
        });
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/storage');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="storage-kit-edit-link"]'));
    }

    public function testAnS3CompatibleProviderNeedsAnEndpoint(): void
    {
        $kernel = $this->bootKernel();
        $this->service($kernel, InstanceSettingsInterface::class)->save('storage', ['provider' => StorageProvider::AWS_S3, 'region' => 'eu-west-1', 'bucket' => 'files', 'access_key' => 'k', 'secret' => 'test-secret-not-real']);
        $settings = $this->service($kernel, InstanceStorageSettings::class);
        self::assertSame('files', $settings->settings()?->bucket);

        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE instance_setting_value SET value = '\"s3_compatible\"' WHERE name = 'provider'");
        $resetter = $this->service($kernel, 'services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        self::assertSame(StorageProvider::S3_COMPATIBLE, $settings->provider());
        self::assertNull($settings->settings());
    }

    public function testOtherSectionsAreIgnored(): void
    {
        $kernel = $this->bootKernel(extra: static function (ContainerConfigurator $container): void {
            $container->services()->alias(InstanceSettingsListener::class . '.public', InstanceSettingsListener::class)->public();
        });
        $listener = $this->service($kernel, InstanceSettingsListener::class . '.public');
        self::assertInstanceOf(InstanceSettingsListener::class, $listener);
        $listener(new SettingsChangedEvent('mailer', ['from']));

        self::assertSame([], $this->audit($kernel)->events);
    }
}
