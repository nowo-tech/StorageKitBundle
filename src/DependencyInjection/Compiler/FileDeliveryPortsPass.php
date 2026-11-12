<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\StorageKitBundle\Download\FileDeliveryInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function class_exists;
use function is_a;
use function is_string;
use function sprintf;

/**
 * Checks the service behind the download port {@see FileDeliveryInterface} (1.5, REQ-ARCH-001 rule 3), once the
 * container knows its final class (after decoration, so a host decorator decides). Runs before the removing passes.
 *
 * - By default the port is the bundle's own delivery (`nowo_storage_kit.file_delivery`), the service the deprecated
 *   `FileDelivery` id also points at.
 * - The host's port wins: an alias of `FileDeliveryInterface` to its own service, or a decorator of
 *   `FileDeliveryInterface`, is what the download controller calls. It must implement the port, or the container build
 *   fails — a file is never served by a service that cannot serve it.
 * - A decorator of the deprecated `FileDelivery` id (a 1.4 decorator offering `respond()`) keeps decorating that id for
 *   the host code that calls it; the port and the download controller are not affected (in 1.4 the controller required
 *   the final `FileDelivery` class, so no decorator could run there).
 *
 * A service whose class cannot be known before the container is compiled (factory, synthetic, unknown class) is left
 * to the controller's typed constructor.
 */
final class FileDeliveryPortsPass implements CompilerPassInterface
{
    /**
     * @throws LogicException when the service behind the download port does not implement it
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(FileDeliveryInterface::class)) {
            return;
        }
        $id    = $this->resolve($container, FileDeliveryInterface::class);
        $class = $this->serviceClass($container, $id);
        if ($class === null || is_a($class, FileDeliveryInterface::class, true)) {
            return;
        }

        throw new LogicException(sprintf('NowoStorageKitBundle: the download port service "%s" (%s) must implement "%s".', $id, $class, FileDeliveryInterface::class));
    }

    /** @return class-string|null null when the class cannot be known before the container is compiled */
    private function serviceClass(ContainerBuilder $container, string $id): ?string
    {
        if (!$container->hasDefinition($id)) {
            return null;
        }
        $definition = $container->getDefinition($id);
        if ($definition->getFactory() !== null || $definition->isSynthetic()) {
            return null;
        }
        // A definition without a class uses its id as the class (as ResolveClassPass does).
        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        return is_string($class) && class_exists($class) ? $class : null;
    }

    /**
     * Follows aliases to the service id that is defined.
     */
    private function resolve(ContainerBuilder $container, string $id): string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id        = (string) $container->getAlias($id);
        }

        return $id;
    }
}
