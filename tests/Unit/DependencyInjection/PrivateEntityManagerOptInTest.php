<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\DependencyInjection;

use Nowo\StorageKitBundle\DependencyInjection\Compiler\StoragePrivateEntityManagerPass;
use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Nowo\StorageKitBundle\Doctrine\OwnEntityManagerSubscriber;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Tests\Support\Deprecations;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function count;
use function sprintf;

/**
 * 2.0 (REQ-ARCH-001 rule 6): the private entity manager is the only mode on the default manager. The setting table of
 * {@see StoragePrivateEntityManagerPass}: which manager the bundle writes through, which notice is triggered and which
 * setup fails the build. A dedicated manager keeps working as in 1.x.
 */
final class PrivateEntityManagerOptInTest extends TestCase
{
    public function testTheExtensionRegistersThePrivateManagerItsMiddlewareAndItsBoundary(): void
    {
        $container = new ContainerBuilder();
        $messages  = Deprecations::collect(fn () => $this->load($container, []));

        self::assertSame([], $messages, 'the extension itself triggers no notice');
        $private = $container->getDefinition(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE);
        self::assertSame(PrivateEntityManager::class, $private->getClass());
        self::assertSame(NowoStorageKitExtension::BUNDLE_NAME, $private->getArgument(3));
        self::assertTrue($private->hasTag(PrivateEntityManager::TAG));
        self::assertTrue($private->hasTag('kernel.reset'));
        self::assertTrue($container->hasDefinition(NowoStorageKitExtension::PRIVATE_MIDDLEWARE_SERVICE));
        $boundary = $container->getDefinition(NowoStorageKitExtension::PRIVATE_BOUNDARY_SERVICE);
        self::assertSame(OwnEntityManagerSubscriber::class, $boundary->getClass());
        self::assertCount(count(OwnEntityManagerSubscriber::LISTENERS), $boundary->getTag('kernel.event_listener'));
        $storage = $container->getDefinition(StorageEntityManager::class);
        self::assertEquals(new Reference(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE), $storage->getArgument(3));
        self::assertCount(4, $storage->getArguments(), 'no host-flush reporter any more');
    }

    /**
     * The setting table: `[private_entity_manager, entity_manager, private manager?, notice]` with the default manager
     * named "main".
     *
     * @return iterable<string, array{bool|null, string|null, bool, string|null}>
     */
    public static function settings(): iterable
    {
        yield 'unset' => [null, null, true, null];
        yield 'unset, entity_manager names the default (W0-1)' => [null, 'main', true, null];
        yield 'true' => [true, null, true, StoragePrivateEntityManagerPass::TRUE_NO_EFFECT];
        yield 'true, entity_manager names the default' => [true, 'main', true, StoragePrivateEntityManagerPass::TRUE_NO_EFFECT];
        yield 'dedicated manager' => [null, 'storage_kit', false, null];
        yield 'dedicated manager, true (the private manager on it, as in 1.8)' => [true, 'storage_kit', true, null];
        yield 'dedicated manager, false (no effect, 1.x gave no notice)' => [false, 'storage_kit', false, sprintf(StoragePrivateEntityManagerPass::FALSE_NO_EFFECT, 'storage_kit')];
    }

    #[DataProvider('settings')]
    public function testTheSettingTable(?bool $private, ?string $named, bool $usesPrivate, ?string $notice): void
    {
        $container = $this->passContainer($private, $named);

        $messages = Deprecations::collect(static fn () => (new StoragePrivateEntityManagerPass())->process($container));

        self::assertSame($notice === null ? [] : ['Since nowo-tech/storage-kit-bundle 2.0: ' . $notice], $messages);
        self::assertSame($usesPrivate, $container->hasDefinition(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE));
        self::assertSame($usesPrivate, $container->hasDefinition(NowoStorageKitExtension::PRIVATE_MIDDLEWARE_SERVICE));
        self::assertSame($usesPrivate, $container->hasDefinition(NowoStorageKitExtension::PRIVATE_BOUNDARY_SERVICE));
        $argument = $container->getDefinition(StorageEntityManager::class)->getArgument(3);
        self::assertSame($usesPrivate, $argument instanceof Reference);
        if ($usesPrivate) {
            self::assertSame($named ?? 'main', $container->getParameter(NowoStorageKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
            self::assertSame([['connection' => 'app']], $container->getDefinition(NowoStorageKitExtension::PRIVATE_MIDDLEWARE_SERVICE)->getTag('doctrine.middleware'));
        }
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function warnedFalse(): iterable
    {
        yield 'false' => [null];
        yield 'false, entity_manager names the default' => ['main'];
    }

    /**
     * `false` while the entities live on the default manager: 1.8 warned that 2.0 removes it.
     */
    #[DataProvider('warnedFalse')]
    public function testFalseOnTheDefaultManagerFailsTheBuild(?string $named): void
    {
        $container = $this->passContainer(false, $named);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(StoragePrivateEntityManagerPass::FALSE_REMOVED);
        (new StoragePrivateEntityManagerPass())->process($container);
    }

    public function testTheMessagesSayWhatToDo(): void
    {
        self::assertStringContainsString('Remove the key', StoragePrivateEntityManagerPass::FALSE_REMOVED);
        self::assertStringContainsString('UPGRADING.md "To 2.0.0"', StoragePrivateEntityManagerPass::FALSE_REMOVED);
        self::assertStringContainsString('remove it. It is removed in 3.0.', StoragePrivateEntityManagerPass::TRUE_NO_EFFECT);
        self::assertStringContainsString('Remove it. It is removed in 3.0.', StoragePrivateEntityManagerPass::FALSE_NO_EFFECT);
    }

    public function testThePassFallsBackToTheManagerNamedDefault(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(NowoStorageKitExtension::PRIVATE_MIDDLEWARE_SERVICE, new Definition('Middleware'));

        (new StoragePrivateEntityManagerPass())->process($container);

        self::assertSame('default', $container->getParameter(NowoStorageKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
    }

    public function testThePassWithoutThePrivateServicesOnlyDecides(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER, 'storage_kit');

        (new StoragePrivateEntityManagerPass())->process($container);

        self::assertFalse($container->hasParameter(NowoStorageKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER));
    }

    public function testADedicatedManagerWithoutTheStorageServiceOnlyRemovesThePrivateOnes(): void
    {
        $container = $this->passContainer(null, 'storage_kit');
        $container->removeDefinition(StorageEntityManager::class);

        (new StoragePrivateEntityManagerPass())->process($container);

        self::assertFalse($container->hasDefinition(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE));
    }

    private function passContainer(?bool $private, ?string $named): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('doctrine.default_entity_manager', 'main');
        $container->setParameter('doctrine.entity_managers', ['main' => 'doctrine.orm.main_entity_manager', 'storage_kit' => 'doctrine.orm.storage_kit_entity_manager']);
        $container->setDefinition('doctrine.orm.main_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.app_connection')]));
        $container->setDefinition('doctrine.orm.storage_kit_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.app_connection')]));
        $container->setParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER, $named);
        $container->setParameter(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER, $private);
        $container->setParameter(NowoStorageKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER, '');
        $container->setDefinition(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE, new Definition(PrivateEntityManager::class));
        $container->setDefinition(NowoStorageKitExtension::PRIVATE_MIDDLEWARE_SERVICE, new Definition('Middleware'));
        $container->setDefinition(NowoStorageKitExtension::PRIVATE_BOUNDARY_SERVICE, new Definition(OwnEntityManagerSubscriber::class));
        $container->setDefinition(StorageEntityManager::class, new Definition(StorageEntityManager::class, [new Reference('doctrine'), $named, 5, new Reference(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE)]));

        return $container;
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
