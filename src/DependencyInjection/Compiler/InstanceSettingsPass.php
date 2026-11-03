<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fail-closed wiring: when the instance bucket is edited in Instance Settings (`instance_profile` null), the
 * container refuses to compile without NowoInstanceSettingsBundle instead of silently turning uploads off.
 */
final class InstanceSettingsPass implements CompilerPassInterface
{
    /** Container parameter: whether the `storage` section is registered. */
    public const PARAMETER = 'nowo_storage_kit.instance_settings';

    /**
     * Throws when the section is registered but Instance Settings is not.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PARAMETER) || $container->getParameter(self::PARAMETER) !== true) {
            return;
        }
        if (!$container->has(InstanceSettingsInterface::class)) {
            throw new LogicException('NowoStorageKitBundle requires nowo-tech/instance-settings-bundle: register Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle in config/bundles.php (the instance bucket is the "storage" Instance Settings section), or manage the instance bucket in the configuration (nowo_storage_kit.instance_profile).');
        }
    }
}
