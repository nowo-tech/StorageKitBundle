<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\DependencyInjection\Compiler;

use Nowo\StorageKitBundle\NowoStorageKitBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function dirname;
use function is_dir;
use function is_string;
use function rtrim;

/**
 * REQ-TWIG-001 / REQ-TWIG-002: registers the `@NowoStorageKitBundle` namespace with the application override
 * directory `templates/bundles/NowoStorageKitBundle` first (when present) and the bundle views last.
 */
final class TwigPathsPass implements CompilerPassInterface
{
    /** Twig namespace. */
    public const TWIG_NAMESPACE = NowoStorageKitBundle::TRANSLATION_DOMAIN;

    /**
     * Adds the loader paths on the native filesystem loader.
     */
    public function process(ContainerBuilder $container): void
    {
        $loaderId = $this->nativeLoaderId($container);
        if ($loaderId === null) {
            return;
        }

        $definition = $container->getDefinition($loaderId);
        $projectDir = $container->hasParameter('kernel.project_dir') ? $container->getParameter('kernel.project_dir') : null;
        if (is_string($projectDir)) {
            $overridePath = rtrim($projectDir, '/\\') . '/templates/bundles/' . self::TWIG_NAMESPACE;
            if (is_dir($overridePath)) {
                // @igor-ignore - Container compile-time DI mutation; not runtime worker state.
                $definition->addMethodCall('prependPath', [$overridePath, self::TWIG_NAMESPACE]);
            }
        }

        // @igor-ignore - Container compile-time DI mutation; not runtime worker state.
        $definition->addMethodCall('addPath', [dirname(__DIR__, 2) . '/Resources/views', self::TWIG_NAMESPACE]);
    }

    private function nativeLoaderId(ContainerBuilder $container): ?string
    {
        foreach (['twig.loader.native', 'twig.loader.native_filesystem'] as $id) {
            for ($i = 0; $i < 32 && $container->hasAlias($id); ++$i) {
                $id = (string) $container->getAlias($id);
            }
            if ($container->hasDefinition($id)) {
                return $id;
            }
        }

        return null;
    }
}
