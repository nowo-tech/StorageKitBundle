<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\DependencyInjection;

use Nowo\StorageKitBundle\DependencyInjection\Compiler\StoragePrivateEntityManagerPass;
use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Nowo\StorageKitBundle\Doctrine\OwnEntityManagerSubscriber;
use Nowo\StorageKitBundle\Doctrine\StorageHostFlushReporter;
use Nowo\StorageKitBundle\Tests\Support\Deprecations;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function count;

/**
 * 1.8 (REQ-ARCH-001 rule 6 deprecation layer): `doctrine.private_entity_manager`. Unset or false while the bundle
 * flushes the application's default manager gives one notice (distinct texts); true or a dedicated manager none.
 */
final class PrivateEntityManagerOptInTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string|null}>
     */
    public static function modes(): iterable
    {
        yield 'unset' => [[], NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION];
        yield 'explicit null' => [['private_entity_manager' => null], NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION];
        yield 'false' => [['private_entity_manager' => false], NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION];
        yield 'true' => [['private_entity_manager' => true], null];
        yield 'dedicated manager, unset' => [['entity_manager' => 'storage_kit'], null];
        yield 'dedicated manager, false' => [['entity_manager' => 'storage_kit', 'private_entity_manager' => false], null];
    }

    /**
     * @param array<string, mixed> $doctrine
     */
    #[DataProvider('modes')]
    public function testTheExtensionNoticeNamesTheOptionOnlyWhenTheDefaultManagerIsFlushed(array $doctrine, ?string $expected): void
    {
        $container = new ContainerBuilder();
        $messages  = Deprecations::collect(fn () => $this->load($container, $doctrine));

        $expected = $expected === null ? [] : ['Since nowo-tech/storage-kit-bundle 1.8: ' . $expected];
        self::assertSame($expected, $messages);
        self::assertSame(($doctrine['private_entity_manager'] ?? null) !== true, $container->getDefinition(StorageHostFlushReporter::class)->getArgument(0), 'probe on unless true');
    }

    public function testTheNoticesNameTheOptionThe20DefaultAndTheOptInLine(): void
    {
        foreach ([NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION, NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION] as $notice) {
            self::assertStringContainsString('nowo_storage_kit.doctrine.private_entity_manager', $notice);
            self::assertStringContainsString('2.0', $notice);
            self::assertStringContainsString('"nowo_storage_kit: { doctrine: { private_entity_manager: true } }"', $notice);
        }
        self::assertStringContainsString('removed in 2.0', NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION);
        self::assertNull(NowoStorageKitExtension::privateEntityManagerNotice(true));
    }

    public function testTrueRegistersThePrivateManagerItsMiddlewareAndItsBoundary(): void
    {
        $container = new ContainerBuilder();
        $this->load($container, ['private_entity_manager' => true]);

        $private = $container->getDefinition(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE);
        self::assertSame(PrivateEntityManager::class, $private->getClass());
        self::assertTrue($private->hasTag(PrivateEntityManager::TAG));
        self::assertTrue($private->hasTag('kernel.reset'));
        self::assertTrue($container->hasDefinition(NowoStorageKitExtension::PRIVATE_MIDDLEWARE_SERVICE));
        $boundary = $container->getDefinition(NowoStorageKitExtension::PRIVATE_BOUNDARY_SERVICE);
        self::assertSame(OwnEntityManagerSubscriber::class, $boundary->getClass());
        self::assertCount(count(OwnEntityManagerSubscriber::LISTENERS), $boundary->getTag('kernel.event_listener'));
        $storage = $container->getDefinition('Nowo\StorageKitBundle\Doctrine\StorageEntityManager');
        self::assertEquals(new Reference(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE), $storage->getArgument(3));
    }

    public function testHostModeRegistersNoPrivateManager(): void
    {
        $container = new ContainerBuilder();
        Deprecations::collect(fn () => $this->load($container, ['private_entity_manager' => false]));

        self::assertFalse($container->hasDefinition(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE));
        self::assertFalse($container->hasDefinition(NowoStorageKitExtension::PRIVATE_MIDDLEWARE_SERVICE));
        self::assertNull($container->getDefinition('Nowo\StorageKitBundle\Doctrine\StorageEntityManager')->getArgument(3));
    }

    /**
     * @return iterable<string, array{bool|null, string|null, string|null}>
     */
    public static function passModes(): iterable
    {
        yield 'unset, named default' => [null, 'main', NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION];
        yield 'false, named default' => [false, 'main', NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION];
        yield 'unset, entity_manager null (the extension notice)' => [null, null, null];
        yield 'unset, dedicated' => [null, 'storage_kit', null];
        yield 'true, named default' => [true, 'main', null];
    }

    #[DataProvider('passModes')]
    public function testThePassNoticeCoversAnEntityManagerNamingTheDefault(?bool $private, ?string $named, ?string $expected): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('doctrine.default_entity_manager', 'main');
        $container->setParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER, $named);
        $container->setParameter(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER, $private);

        $messages = Deprecations::collect(static fn () => (new StoragePrivateEntityManagerPass())->process($container));

        self::assertSame($expected === null ? [] : ['Since nowo-tech/storage-kit-bundle 1.8: ' . $expected], $messages);
    }

    public function testThePassPutsThePrivateManagerOnTheDefaultManagersConnection(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('doctrine.default_entity_manager', 'main');
        $container->setParameter('doctrine.entity_managers', ['main' => 'doctrine.orm.main_entity_manager']);
        $container->setDefinition('doctrine.orm.main_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.app_connection')]));
        $container->setParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER, null);
        $container->setParameter(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER, true);
        $container->setParameter(NowoStorageKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER, '');
        $container->setDefinition(NowoStorageKitExtension::PRIVATE_MIDDLEWARE_SERVICE, new Definition('Middleware'));

        (new StoragePrivateEntityManagerPass())->process($container);

        self::assertSame('main', $container->getParameter(NowoStorageKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
        self::assertSame([['connection' => 'app']], $container->getDefinition(NowoStorageKitExtension::PRIVATE_MIDDLEWARE_SERVICE)->getTag('doctrine.middleware'));
    }

    public function testThePassFallsBackToTheManagerNamedDefault(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER, true);
        $container->setDefinition(NowoStorageKitExtension::PRIVATE_MIDDLEWARE_SERVICE, new Definition('Middleware'));

        (new StoragePrivateEntityManagerPass())->process($container);

        self::assertSame('default', $container->getParameter(NowoStorageKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
    }

    /**
     * @param array<string, mixed> $doctrine
     */
    private function load(ContainerBuilder $container, array $doctrine): void
    {
        $container->setParameter('kernel.bundles', ['NowoDoctrineEncryptBundle' => 'x', 'SecurityBundle' => 'y']);
        (new NowoStorageKitExtension(false))->load([[
            'instance_profile' => 'main',
            'profiles'         => ['main' => ['bucket' => 'files', 'endpoint' => 'https://s3.example.test', 'access_key' => 'k', 'secret' => 's']],
            'doctrine'         => $doctrine,
        ]], $container);
    }
}
