<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\DependencyInjection\Compiler;

use Nowo\StorageKitBundle\MessageHandler\RunStorageSyncHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Without a Messenger bus (`messenger.default_bus`) the move sync handler is removed: syncs then run with
 * `nowo:storage-kit:sync` (the Move page says so).
 */
final class MessengerIntegrationPass implements CompilerPassInterface
{
    /**
     * Removes the handler when there is no bus.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has('messenger.default_bus')) {
            $container->removeDefinition(RunStorageSyncHandler::class);
        }
    }
}
