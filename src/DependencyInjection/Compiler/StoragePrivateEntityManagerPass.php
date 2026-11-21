<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\DependencyInjection\Compiler;

use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\TransactionDoctrine\PrivateManager\DependencyInjection\PrivateEntityManagerPass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_string;
use function sprintf;
use function trigger_deprecation;

/**
 * REQ-ARCH-001 rule 6 (1.8 opt-in, the only mode since 2.0): which manager the bundle entities are written through.
 *
 * | `doctrine.entity_manager`            | `private_entity_manager` | Manager                                         |
 * |--------------------------------------|--------------------------|-------------------------------------------------|
 * | unset, or the default manager's name | unset                    | the private manager on the default manager      |
 * | unset, or the default manager's name | `true`                   | the same; deprecation: the key has no effect    |
 * | unset, or the default manager's name | `false`                  | build error (1.8 warned: 2.0 removes it)        |
 * | a dedicated manager                  | unset                    | the dedicated manager, directly (as in 1.x)     |
 * | a dedicated manager                  | `true`                   | the private manager on it (as in 1.8)           |
 * | a dedicated manager                  | `false`                  | the dedicated manager; deprecation: no effect   |
 *
 * The default manager is `doctrine.default_entity_manager`, so one not named "default" works without configuration,
 * and `entity_manager` naming it is the default manager, never a dedicated one (lesson W0-1). In private mode the
 * pass runs transaction-doctrine's {@see PrivateEntityManagerPass}, which checks that the source manager exists and
 * tags the bundle's PrivateEntityManagerMiddleware `doctrine.middleware` for its connection only. Otherwise it removes
 * the private manager's services and hands {@see StorageEntityManager} no private manager.
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0).
 *
 * @internal
 */
final class StoragePrivateEntityManagerPass implements CompilerPassInterface
{
    /** 2.0: `private_entity_manager: false` where 1.8 warned about it (the bundle entities on the default manager). */
    public const FALSE_REMOVED = 'nowo_storage_kit.doctrine.private_entity_manager: false is no longer supported: since 2.0 the bundle always writes its entities through a private entity manager on the same connection and tables, and never flushes your entity manager or your pending changes. Remove the key (or name a dedicated manager with doctrine.entity_manager). See UPGRADING.md "To 2.0.0".';

    /** 2.0: `private_entity_manager: true` while it changes nothing (the private manager is the only mode there). */
    public const TRUE_NO_EFFECT = 'The option "nowo_storage_kit.doctrine.private_entity_manager" has no effect since 2.0 (the private entity manager is the only mode); remove it. It is removed in 3.0.';

    /** 2.0: `private_entity_manager: false` with a dedicated manager (1.x used the dedicated manager either way). */
    public const FALSE_NO_EFFECT = 'The option "nowo_storage_kit.doctrine.private_entity_manager: false" has no effect since 2.0: the dedicated doctrine.entity_manager "%s" is used directly, as before. Remove it. It is removed in 3.0.';

    /**
     * Decides the manager (table above), then tags the private manager's middleware or removes its services.
     *
     * @throws InvalidConfigurationException on `private_entity_manager: false` while the entities live on the default manager
     */
    public function process(ContainerBuilder $container): void
    {
        $default   = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : null;
        $default   = is_string($default) && $default !== '' ? $default : 'default';
        $named     = $container->hasParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER) ? $container->getParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER) : null;
        $named     = is_string($named) && $named !== '' ? $named : null;
        $private   = $container->hasParameter(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER) ? $container->getParameter(NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_PARAMETER) : null;
        $dedicated = $named !== null && $named !== $default;

        if ($private === false && !$dedicated) {
            throw new InvalidConfigurationException(self::FALSE_REMOVED);
        }
        if ($private === false) {
            trigger_deprecation('nowo-tech/storage-kit-bundle', '2.0', sprintf(self::FALSE_NO_EFFECT, $named));
        } elseif ($private === true && !$dedicated) {
            trigger_deprecation('nowo-tech/storage-kit-bundle', '2.0', self::TRUE_NO_EFFECT);
        }

        if (!$container->hasDefinition(NowoStorageKitExtension::PRIVATE_MIDDLEWARE_SERVICE)) {
            return;
        }
        if ($dedicated && $private !== true) {
            foreach ([NowoStorageKitExtension::PRIVATE_BOUNDARY_SERVICE, NowoStorageKitExtension::PRIVATE_MIDDLEWARE_SERVICE, NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_SERVICE] as $id) {
                $container->removeDefinition($id);
            }
            if ($container->hasDefinition(StorageEntityManager::class)) {
                $container->getDefinition(StorageEntityManager::class)->replaceArgument(3, null);
            }

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
