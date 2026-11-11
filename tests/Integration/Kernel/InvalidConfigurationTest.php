<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Kernel;

use LogicException;
use Nowo\StorageKitBundle\Security\AccessGuard;
use Nowo\StorageKitBundle\Security\EndpointGuard;
use Nowo\StorageKitBundle\Settings\InstanceStorageSettings;
use Nowo\StorageKitBundle\Settings\StorageSettingsSection;
use Nowo\StorageKitBundle\Upload\UploadEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Throwable;

/**
 * REQ-SF-006 fail-closed configuration: P1 role lists, debug-only switches, literal booleans, scopes, profiles, paths,
 * CSRF and the required bundles.
 */
final class InvalidConfigurationTest extends KernelTestCase
{
    /** The instance bucket managed by the configuration (no Instance Settings section). */
    private const CONFIG_INSTANCE = ['profiles' => ['main' => ['dsn' => 's3://test-key:test-secret-not-real@minio:9000/main?tls=0']], 'instance_profile' => 'main'];

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty access roles' => [['security' => ['access_roles' => []]], 'access_roles must contain at least one'];
        yield 'blank access role' => [['security' => ['access_roles' => [' ']]], 'access_roles must contain at least one'];
        yield 'empty upload roles' => [['upload' => ['access_roles' => []]], 'upload.access_roles must contain'];
        yield 'unauthenticated outside debug' => [['security' => ['allow_unauthenticated' => true]], 'only allowed when kernel.debug'];
        yield 'private endpoints outside debug' => [['endpoints' => ['allow_private' => true]], 'only allowed when kernel.debug'];
        yield 'env placeholder switch' => [['upload' => ['enabled' => '%env(bool:STORAGE_UPLOADS)%']], 'literal boolean'];
        yield 'scopes without checker' => [['scopes' => ['enabled' => true, 'access_checker' => null]], 'access_checker must be set'];
        yield 'unknown instance profile' => [['instance_profile' => 'nope'], 'is not a configured profile'];
        yield 'profiles tree and provider' => [['config_profile_provider' => 'app.buckets', 'profiles' => ['main' => ['dsn' => 's3://k:s@aws/b']]], 'are exclusive'];
        yield 'profile without credentials' => [['profiles' => ['main' => ['bucket' => 'b']]], 'needs a "dsn"'];
        yield 'profile name' => [['profiles' => ['Main' => ['dsn' => 's3://k:s@aws/b']]], 'names must be'];
        yield 'profile delivery' => [['profiles' => ['main' => ['dsn' => 's3://k:s@aws/b', 'delivery' => 'public']]], 'delivery'];
        yield 'scheme' => [['uri_scheme' => 'http://'], 'uri_scheme'];
        yield 'admin prefix' => [['web_ui' => ['path_prefix' => 'admin']], 'web_ui.path_prefix'];
        yield 'scope prefix under admin' => [['scopes' => ['enabled' => true, 'access_checker' => 'x', 'path_prefix' => '/admin/storage/scopes']], 'must not be under'];
        yield 'files prefix equals admin' => [['upload' => ['path_prefix' => '/admin/storage']], 'must not be under'];
        yield 'files prefix' => [['upload' => ['path_prefix' => '/']], 'upload.path_prefix'];
        yield 'scope prefix' => [['scopes' => ['path_prefix' => 'x']], 'scopes.path_prefix'];
        yield 'variants' => [['upload' => ['variants' => ['huge' => 3000]]], 'preview" and "thumb'];
        yield 'rate interval' => [['upload' => ['rate_interval' => 'soon']], 'rate_interval'];
        yield 'template page' => [['web_ui' => ['templates' => ['list' => 'x.html.twig']]], 'Unknown nowo_storage_kit.web_ui.templates'];
        yield 'table prefix' => [['doctrine' => ['table_prefix' => 'app-']], 'table_prefix'];
        yield 'ttl' => [['download' => ['url_ttl' => 10]], 'url_ttl'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalid')]
    public function testInvalidConfigurationsAreRefused(array $config, string $message): void
    {
        try {
            $this->bootKernel($config);
            self::fail('accepted');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testCsrfIsRequiredByThePagesAndTheUploadEndpoint(): void
    {
        foreach ([self::CONFIG_INSTANCE, ['web_ui' => ['enabled' => false]] + self::CONFIG_INSTANCE] as $config) {
            try {
                $this->bootKernel($config, ['csrf', 'settings']);
                self::fail('accepted');
            } catch (LogicException $e) {
                self::assertStringContainsString('CSRF', $e->getMessage());
            }
        }
        $kernel = $this->bootKernel(['web_ui' => ['enabled' => false], 'upload' => ['enabled' => false]] + self::CONFIG_INSTANCE, ['csrf', 'settings']);
        self::assertTrue($this->container($kernel)->has(UploadEndpoint::class));
    }

    public function testFormCsrfIsRequiredByThePages(): void
    {
        try {
            $this->bootKernel(self::CONFIG_INSTANCE, ['settings'], extra: static function (ContainerConfigurator $container): void {
                $container->extension('framework', ['form' => ['csrf_protection' => false]]);
            });
            self::fail('accepted');
        } catch (LogicException $e) {
            self::assertStringContainsString('form CSRF protection', $e->getMessage());
        }
    }

    public function testTheRequiredBundles(): void
    {
        foreach ([[['encrypt'], 'doctrine-encrypt-bundle'], [['security', 'settings'], 'symfony/security-bundle']] as [$without, $message]) {
            try {
                $this->bootKernel(self::CONFIG_INSTANCE, $without);
                self::fail('accepted without ' . $without[0]);
            } catch (Throwable $e) {
                self::assertInstanceOf(LogicException::class, $e);
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        $kernel = $this->bootKernel(['web_ui' => ['enabled' => false]] + self::CONFIG_INSTANCE, ['security', 'settings']);
        self::assertTrue($this->container($kernel)->has(AccessGuard::class));
        self::assertFalse($this->container($kernel)->has(StorageSettingsSection::class));
    }

    public function testInstanceSettingsIsRequiredUnlessTheConfigurationManagesTheInstanceBucket(): void
    {
        try {
            $this->bootKernel([], ['settings']);
            self::fail('accepted');
        } catch (LogicException $e) {
            self::assertStringContainsString('requires nowo-tech/instance-settings-bundle', $e->getMessage());
        }
        $kernel = $this->bootKernel(self::CONFIG_INSTANCE, ['settings']);
        self::assertFalse($this->container($kernel)->has(InstanceStorageSettings::class));
    }

    public function testDebugSwitchesAreAcceptedInDebug(): void
    {
        $kernel = $this->bootKernel(['security' => ['allow_unauthenticated' => true], 'endpoints' => ['allow_private' => true]], debug: true);

        self::assertTrue($this->container($kernel)->has(EndpointGuard::class));
    }
}
