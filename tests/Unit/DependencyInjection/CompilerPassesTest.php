<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\DependencyInjection;

use Nowo\StorageKitBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\StorageKitBundle\DependencyInjection\Compiler\DebugOnlySwitchesPass;
use Nowo\StorageKitBundle\DependencyInjection\Compiler\MessengerIntegrationPass;
use Nowo\StorageKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Nowo\StorageKitBundle\MessageHandler\RunStorageSyncHandler;
use Nowo\StorageKitBundle\NowoStorageKitBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function sys_get_temp_dir;
use function uniqid;

final class CompilerPassesTest extends TestCase
{
    public function testTwigPathsPrependTheApplicationOverrides(): void
    {
        $project = sys_get_temp_dir() . '/storage-kit-twig-' . uniqid();
        mkdir($project . '/templates/bundles/NowoStorageKitBundle', 0o777, true);
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $project);
        $container->setDefinition('twig.loader.native_filesystem', new Definition());
        $container->setAlias('twig.loader.native', 'twig.loader.native_filesystem');
        (new TwigPathsPass())->process($container);

        $calls = $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls();
        self::assertSame(['prependPath', 'addPath'], [$calls[0][0], $calls[1][0]]);
        self::assertSame('NowoStorageKitBundle', $calls[1][1][1]);

        (new TwigPathsPass())->process(new ContainerBuilder());
        rmdir($project . '/templates/bundles/NowoStorageKitBundle');
    }

    public function testTheHandlerIsRemovedWithoutABus(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(RunStorageSyncHandler::class, new Definition());
        (new MessengerIntegrationPass())->process($container);
        self::assertFalse($container->hasDefinition(RunStorageSyncHandler::class));

        $container = new ContainerBuilder();
        $container->setDefinition(RunStorageSyncHandler::class, new Definition());
        $container->setDefinition('messenger.default_bus', new Definition());
        (new MessengerIntegrationPass())->process($container);
        self::assertTrue($container->hasDefinition(RunStorageSyncHandler::class));
    }

    public function testPassesWithNothingToCheck(): void
    {
        $container = new ContainerBuilder();
        (new CsrfProtectionPass())->process($container);
        (new DebugOnlySwitchesPass())->process($container);
        $container->setParameter('kernel.debug', true);
        $container->setParameter(DebugOnlySwitchesPass::UNAUTHENTICATED, true);
        (new DebugOnlySwitchesPass())->process($container);
        self::assertSame('storage_kit', NowoStorageKitExtension::formKitProfile()['alias']);
        self::assertSame('nowo_storage_kit', (new NowoStorageKitBundle())->getContainerExtension()?->getAlias());
    }
}
