<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\DependencyInjection\Compiler;

use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Nowo\TransactionDoctrine\PrivateManager\DependencyInjection\PrivateEntityManagerPass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_bool;
use function is_string;
use function trigger_deprecation;

/**
 * 1.8 (REQ-ARCH-001 rule 6, `doctrine.private_entity_manager`).
 *
 * - Private mode (`true`): resolves the application's manager the private one is created on — `doctrine.entity_manager`,
 *   else the application's default entity manager (`doctrine.default_entity_manager`, so a default manager not named
 *   "default" works without configuration) — then runs transaction-doctrine's {@see PrivateEntityManagerPass}, which
 *   checks that manager exists and tags the bundle's PrivateEntityManagerMiddleware `doctrine.middleware` for its
 *   connection only.
 * - Unset (`null`) or `false` with `doctrine.entity_manager` naming the application's default manager: the bundle
 *   flushes that manager, as with `entity_manager: null`, so the same deprecation notice is triggered (the extension
 *   triggers it for `entity_manager: null`; it cannot know the default manager's name).
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0).
 *
 * @internal
 */
final class StoragePrivateEntityManagerPass implements CompilerPassInterface
{
    /**
     * Resolves the source manager and tags the middleware (private mode), or triggers the notice (unset, default manager).
     */
    public function process(ContainerBuilder $container): void
    {
        $default = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
        $default = is_string($default) && $default !== '' ? $default : 'default';
        $named   = $container->hasParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER) ? $container->getParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER) : null;
        $named   = is_string($named) && $named !== '' ? $named : null;
        $private = $container->hasParameter(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER) ? $container->getParameter(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER) : true;
        $notice  = NowoStorageKitExtension::privateEntityManagerNotice(is_bool($private) ? $private : null);

        if ($notice !== null && $named === $default) {
            trigger_deprecation('nowo-tech/storage-kit-bundle', '1.8', $notice);
        }

        if (!$container->hasDefinition(NowoStorageKitExtension::PRIVATE_MIDDLEWARE_SERVICE)) {
            return;
        }

        $container->setParameter(NowoStorageKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER, $named ?? $default);
        (new PrivateEntityManagerPass(
            NowoStorageKitExtension::PRIVATE_MIDDLEWARE_SERVICE,
            NowoStorageKitExtension::PRIVATE_SOURCE_MANAGER_PARAMETER,
            'nowo_storage_kit.doctrine.entity_manager',
        ))->process($container);
    }
}
