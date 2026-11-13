<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\DependencyInjection;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\StorageKitBundle\DependencyInjection\Compiler\FileDeliveryPortsPass;
use Nowo\StorageKitBundle\DependencyInjection\Compiler\StorageEntityManagerPass;
use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Nowo\StorageKitBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Nowo\StorageKitBundle\Doctrine\OwnEntityManagerSubscriber;
use Nowo\StorageKitBundle\Download\FileDelivery;
use Nowo\StorageKitBundle\Download\FileDeliveryInterface;
use Nowo\StorageKitBundle\Tests\Fixtures\Ports\NotADelivery;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function array_keys;
use function array_map;
use function implode;
use function str_contains;

/**
 * 1.5 compile-time wiring: the dedicated entity manager pass, the download port pass, the Doctrine mapping prepend for
 * every host Doctrine layout, and the own-manager boundary subscriber.
 */
final class ArchitecturePassesTest extends TestCase
{
    public function testTheEntityManagerPassDoesNothingWithoutADedicatedManager(): void
    {
        $container = new ContainerBuilder();
        (new StorageEntityManagerPass())->process($container);
        $container->setParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER, null);
        (new StorageEntityManagerPass())->process($container);

        self::assertFalse($container->hasDefinition(OwnEntityManagerSubscriber::class));
    }

    public function testTheEntityManagerPassChecksTheManagerAndItsMapping(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER, 'storage_kit');
        $container->setParameter('doctrine.entity_managers', ['default' => 'x', 'storage_kit' => 'y', 'reports' => 'z']);
        $container->setParameter('doctrine.default_entity_manager', 'default');
        (new StorageEntityManagerPass(checkOnly: true))->process($container);
        self::assertFalse($container->hasDefinition(OwnEntityManagerSubscriber::class), 'check only');

        $bundleDriver  = ['addDriver', [new Definition('Driver'), StorageEntityManagerPass::ENTITY_NAMESPACE]];
        $appDriver     = ['addDriver', [new Definition('Driver'), 'App\\Entity']];
        $defaultDriver = ['setDefaultDriver', [new Reference('auto_discover')]];
        $container->setDefinition('doctrine.orm.default_metadata_driver', (new Definition('Chain'))->setMethodCalls([$appDriver, $bundleDriver, $defaultDriver]));
        $container->setDefinition('doctrine.orm.storage_kit_metadata_driver', (new Definition('Chain'))->setMethodCalls([$bundleDriver]));
        $container->setDefinition('doctrine.orm.reports_metadata_driver', new Definition('Chain'));
        for ($run = 0; $run < 2; ++$run) {
            (new StorageEntityManagerPass())->process($container);

            // Other managers: the host's driver for the namespace is gone; a driver that maps nothing comes first,
            // before the host's drivers and before any default driver (DoctrineBundle 3.4 auto_discover_entities).
            $default = $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls();
            self::assertSame(
                [['addDriver', StorageEntityManagerPass::ENTITY_NAMESPACE], ['addDriver', 'App\\Entity'], ['setDefaultDriver', null]],
                array_map(static fn (array $call): array => [$call[0], $call[1][1] ?? null], $default),
                'run ' . $run . ' (idempotent)',
            );
            self::assertInstanceOf(Definition::class, $default[0][1][0]);
            self::assertSame(ExcludedEntityNamespaceDriver::class, $default[0][1][0]->getClass());
            self::assertSame([$appDriver, $defaultDriver], [$default[1], $default[2]]);
            self::assertSame([$bundleDriver], $container->getDefinition('doctrine.orm.storage_kit_metadata_driver')->getMethodCalls());
            self::assertSame([['addDriver', StorageEntityManagerPass::ENTITY_NAMESPACE]], array_map(static fn (array $call): array => [$call[0], $call[1][1] ?? null], $container->getDefinition('doctrine.orm.reports_metadata_driver')->getMethodCalls()));
        }
        self::assertTrue($container->hasDefinition(OwnEntityManagerSubscriber::class));

        $container->getDefinition('doctrine.orm.storage_kit_metadata_driver')->setMethodCalls([$appDriver]);
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The Doctrine entity manager "storage_kit" (nowo_storage_kit.doctrine.entity_manager) does not map Nowo\StorageKitBundle\Entity.');
        (new StorageEntityManagerPass())->process($container);
    }

    /**
     * Audit NH-6: a bundle manager on another connection than the host's default one is reported at build time.
     */
    #[IgnoreDeprecations]
    public function testAnotherConnectionIsReported(): void
    {
        foreach (['other' => true, 'default' => false] as $connection => $reported) {
            $container = new ContainerBuilder();
            $container->setParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER, 'storage_kit');
            $container->setParameter('doctrine.entity_managers', ['default' => 'x', 'storage_kit' => 'y', 'factory' => 'z']);
            $container->setParameter('doctrine.default_entity_manager', 'default');
            $container->setDefinition('doctrine.orm.default_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.default_connection')]));
            $container->setDefinition('doctrine.orm.storage_kit_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.' . $connection . '_connection')]));
            $container->setDefinition('doctrine.orm.storage_kit_metadata_driver', (new Definition('Chain'))->setMethodCalls([['addDriver', [new Definition('Driver'), StorageEntityManagerPass::ENTITY_NAMESPACE]]]));
            if ($reported) {
                $this->expectUserDeprecationMessage('Since nowo-tech/storage-kit-bundle 1.5: nowo_storage_kit.doctrine.entity_manager "storage_kit" uses the DBAL connection "doctrine.dbal.other_connection", the default entity manager "default" uses "doctrine.dbal.default_connection": the GDPR erasure, the settings listener, uploads and file references are then not atomic with your transactions. Put the bundle manager on the same connection.');
            }
            (new StorageEntityManagerPass())->process($container);
            self::assertSame($reported, str_contains(implode("\n", $container->getCompiler()->getLog()), 'not atomic'), $connection);
        }
        // Unknown definitions (another manager kind) are not reported.
        $container = new ContainerBuilder();
        $container->setParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER, 'storage_kit');
        $container->setParameter('doctrine.entity_managers', ['default' => 'x', 'storage_kit' => 'y']);
        $container->setParameter('doctrine.default_entity_manager', 'default');
        $container->setDefinition('doctrine.orm.storage_kit_entity_manager', new Definition('EM', ['not a reference']));
        (new StorageEntityManagerPass())->process($container);
        self::assertSame([], $container->getCompiler()->getLog());
    }

    public function testTheHostDefaultManagerGetsNoBoundarySubscriber(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER, 'main');
        $container->setParameter('doctrine.entity_managers', ['main' => 'x']);
        $container->setParameter('doctrine.default_entity_manager', 'main');
        (new StorageEntityManagerPass())->process($container);

        self::assertFalse($container->hasDefinition(OwnEntityManagerSubscriber::class));
    }

    public function testAnUnknownManagerIsReportedWithTheKnownOnes(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER, 'storage_kit');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('no such Doctrine entity manager is configured (known: "")');
        (new StorageEntityManagerPass(checkOnly: true))->process($container);
    }

    public function testTheDeliveryPassLeavesWhatItCannotKnowToTheRuntime(): void
    {
        $container = new ContainerBuilder();
        (new FileDeliveryPortsPass())->process($container);

        foreach ([
            'factory'   => (new Definition(FileDelivery::class))->setFactory(['Factory', 'create']),
            'synthetic' => (new Definition(FileDelivery::class))->setSynthetic(true),
            'unknown'   => new Definition('App\\Missing\\Delivery'),
            'port'      => new Definition(FileDelivery::class),
        ] as $id => $definition) {
            $container->setDefinition($id, $definition);
            $container->setAlias('chain', $id);
            $container->setAlias(FileDeliveryInterface::class, 'chain');
            (new FileDeliveryPortsPass())->process($container);
        }
        $container->setAlias(FileDeliveryInterface::class, 'missing');
        (new FileDeliveryPortsPass())->process($container);

        $container->setDefinition(NotADelivery::class, new Definition());
        $container->setAlias(FileDeliveryInterface::class, NotADelivery::class);
        $this->expectException(LogicException::class);
        (new FileDeliveryPortsPass())->process($container);
    }

    public function testTheMappingIsPrependedForEveryHostDoctrineLayout(): void
    {
        $cases = [
            'no option'                  => [[], [], ['mappings']],
            'empty name'                 => [['doctrine' => ['entity_manager' => '']], [], ['mappings']],
            'default, shorthand'         => [['doctrine' => ['entity_manager' => 'default']], [['dbal' => []]], ['mappings']],
            'declared, default set'      => [['doctrine' => ['entity_manager' => 'storage_kit']], [['orm' => ['default_entity_manager' => 'main', 'entity_managers' => ['main' => [], 'storage_kit' => []]]]], ['default_entity_manager', 'entity_managers'], 'main'],
            'declared, first is default' => [['doctrine' => ['entity_manager' => 'storage_kit']], [['orm' => ['entity_managers' => ['app' => [], 'storage_kit' => []]]]], ['default_entity_manager', 'entity_managers'], 'app'],
            'not declared'               => [['doctrine' => ['entity_manager' => 'storage_kit']], [['orm' => ['entity_managers' => ['app' => []]]]], null],
            'not declared, no list'      => [['doctrine' => ['entity_manager' => 'storage_kit']], [['orm' => []]], null],
        ];
        foreach ($cases as $case => $spec) {
            [$storageKit, $doctrine, $keys] = $spec;
            $container                      = new ContainerBuilder();
            $container->registerExtension(new class extends Extension {
                public function load(array $configs, ContainerBuilder $container): void
                {
                }

                public function getAlias(): string
                {
                    return 'doctrine';
                }
            });
            foreach ($doctrine as $config) {
                $container->loadFromExtension('doctrine', $config);
            }
            $extension = new NowoStorageKitExtension();
            $container->registerExtension($extension);
            $container->loadFromExtension('nowo_storage_kit', $storageKit);
            $extension->prepend($container);
            $first = $container->getExtensionConfig('doctrine')[0] ?? [];
            if ($keys === null) {
                self::assertSame($doctrine[0] ?? null, $first, $case . ': nothing prepended (the pass reports the unknown manager)');
                continue;
            }
            self::assertSame($keys, array_keys($first['orm']), $case);
            if (isset($spec[3])) {
                self::assertSame($spec[3], $first['orm']['default_entity_manager'], $case);
                self::assertArrayHasKey('NowoStorageKitBundle', $first['orm']['entity_managers']['storage_kit']['mappings'], $case);
            }
        }
    }

    public function testTheSubscriberRecoversOnMainRequestsOnly(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(false);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->with('storage_kit')->willReturn($manager);
        $registry->expects(self::once())->method('resetManager')->with('storage_kit');
        $manager->expects(self::never())->method('clear');
        $subscriber = new OwnEntityManagerSubscriber($registry, 'storage_kit');
        $kernel     = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        $subscriber->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        $subscriber->clear();
    }
}
