<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle;

use Nowo\StorageKitBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\StorageKitBundle\DependencyInjection\Compiler\DebugOnlySwitchesPass;
use Nowo\StorageKitBundle\DependencyInjection\Compiler\InstanceSettingsPass;
use Nowo\StorageKitBundle\DependencyInjection\Compiler\MessengerIntegrationPass;
use Nowo\StorageKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * S3-compatible object storage for Symfony: per-scope buckets with encrypted credentials, image uploads for Markdown
 * editors, reference-aware purge, verified bucket moves and an SSRF-guarded endpoint test.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoStorageKitBundle extends Bundle
{
    /** Translation domain of the bundle UI copy (REQ-I18N-003) and Twig namespace (REQ-TWIG-002). */
    public const TRANSLATION_DOMAIN = 'NowoStorageKitBundle';

    /** FormKit profile (`nowo_form_kit.profiles.storage_kit`) of every bundle form type (REQ-TWIG-005 rule 11). */
    public const FORM_KIT_PROFILE = 'storage_kit';

    /**
     * Registers the compiler passes: Twig paths, Messenger integration, CSRF fail-closed guard and the debug-only
     * switches guard.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TwigPathsPass());
        // Before the message handlers are collected (MessengerPass runs in TYPE_BEFORE_OPTIMIZATION).
        $container->addCompilerPass(new MessengerIntegrationPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
        $container->addCompilerPass(new CsrfProtectionPass());
        $container->addCompilerPass(new DebugOnlySwitchesPass());
        $container->addCompilerPass(new InstanceSettingsPass());
    }

    /**
     * The extension alias is `nowo_storage_kit` (not the default derived from the class name).
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoStorageKitExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}
