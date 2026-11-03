<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Dev / demo switches refuse to compile unless `kernel.debug` is true, so a demo configuration copied to production
 * cannot open the storage pages without login (`security.allow_unauthenticated`) or let page-edited buckets reach
 * private addresses (`endpoints.allow_private`).
 */
final class DebugOnlySwitchesPass implements CompilerPassInterface
{
    /** Container parameter holding `security.allow_unauthenticated`. */
    public const UNAUTHENTICATED = 'nowo_storage_kit.security.allow_unauthenticated';

    /** Container parameter holding `endpoints.allow_private`. */
    public const ALLOW_PRIVATE = 'nowo_storage_kit.endpoints.allow_private';

    /**
     * Throws when a switch is on outside debug.
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasParameter('kernel.debug') && $container->getParameter('kernel.debug') === true) {
            return;
        }
        if ($container->hasParameter(self::UNAUTHENTICATED) && $container->getParameter(self::UNAUTHENTICATED) === true) {
            throw new InvalidConfigurationException('nowo_storage_kit.security.allow_unauthenticated: true is only allowed when kernel.debug is true (dev / demo): the storage pages would let anybody change the buckets. Set it to false and protect the pages with security.access_roles.');
        }
        if ($container->hasParameter(self::ALLOW_PRIVATE) && $container->getParameter(self::ALLOW_PRIVATE) === true) {
            throw new InvalidConfigurationException('nowo_storage_kit.endpoints.allow_private: true is only allowed when kernel.debug is true (dev / demo): buckets typed into a page could reach private addresses. List the internal hosts in endpoints.allowed_hosts instead.');
        }
    }
}
