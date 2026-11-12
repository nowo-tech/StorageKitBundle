<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\DependencyInjection\Compiler;

use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Nowo\StorageKitBundle\Doctrine\OwnEntityManagerSubscriber;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Keeps the bundle entities on `doctrine.entity_manager` only (1.5, architecture bar rule 6).
 *
 * DoctrineBundle's `auto_mapping` (the Flex default on the default manager) maps every bundle's `Entity` directory, so
 * with a dedicated manager the entities would be mapped twice and `getManagerForClass()` would return the default
 * manager. This pass removes the bundle namespace from the mapping driver chain of every other manager and fails the
 * build when the configured manager does not exist or does not map the entities. A dedicated manager (not the host
 * default) also gets {@see OwnEntityManagerSubscriber}: recovered when closed, cleared at every boundary.
 */
final readonly class StorageEntityManagerPass implements CompilerPassInterface
{
    /** Namespace of the bundle entities. */
    public const ENTITY_NAMESPACE = 'Nowo\\StorageKitBundle\\Entity';

    /**
     * @param bool $checkOnly only fail fast on an unknown manager (registered before the mapping passes, which would
     *                        otherwise fail first with a less helpful "non-existent service" error)
     */
    public function __construct(
        private bool $checkOnly = false,
    ) {
    }

    /**
     * Validates the configured manager, keeps the entities mapped on it only and wires its boundary subscriber.
     *
     * @throws InvalidConfigurationException when the manager is not declared or does not map the bundle entities
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER)) {
            return;
        }
        $name = $container->getParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER);
        if (!is_string($name) || $name === '') {
            return;
        }

        $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
        $managers = is_array($managers) ? $managers : [];
        if (!isset($managers[$name])) {
            throw new InvalidConfigurationException(sprintf('nowo_storage_kit.doctrine.entity_manager is "%s", but no such Doctrine entity manager is configured (known: "%s"). Add it under doctrine.orm.entity_managers or remove the option.', $name, implode('", "', array_keys($managers))));
        }
        if ($this->checkOnly) {
            return;
        }

        foreach (array_keys($managers) as $manager) {
            $chainId = sprintf('doctrine.orm.%s_metadata_driver', $manager);
            if (!$container->hasDefinition($chainId)) {
                continue;
            }
            $chain = $container->getDefinition($chainId);
            $calls = [];
            $maps  = false;
            foreach ($chain->getMethodCalls() as $call) {
                $isBundleDriver = $call[0] === 'addDriver' && is_string($call[1][1] ?? null) && str_starts_with($call[1][1], self::ENTITY_NAMESPACE);
                if ($isBundleDriver && $manager !== $name) {
                    continue;
                }
                $maps    = $maps || $isBundleDriver;
                $calls[] = $call;
            }
            $chain->setMethodCalls($calls);
            if ($manager === $name && !$maps) {
                throw new InvalidConfigurationException(sprintf('The Doctrine entity manager "%s" (nowo_storage_kit.doctrine.entity_manager) does not map %s.', $name, self::ENTITY_NAMESPACE));
            }
        }

        // A dedicated manager belongs to the bundle: cleared at every boundary, reset when closed. Never the host's.
        if (!$container->hasParameter('doctrine.default_entity_manager') || $container->getParameter('doctrine.default_entity_manager') !== $name) {
            $definition = $container->setDefinition(OwnEntityManagerSubscriber::class, (new Definition(OwnEntityManagerSubscriber::class, [new Reference('doctrine'), $name]))->setPublic(true));
            foreach (OwnEntityManagerSubscriber::LISTENERS as [$event, $method, $priority]) {
                $definition->addTag('kernel.event_listener', ['event' => $event, 'method' => $method, 'priority' => $priority]);
            }
        }
    }
}
