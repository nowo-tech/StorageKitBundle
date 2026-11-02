<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\StorageKitBundle\Controller\FileUploadController;
use Nowo\StorageKitBundle\Controller\StorageSettingsController;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails closed when a mutating endpoint is registered without CSRF protection (REQ-SEC-005, pattern P2): the Storage
 * and Move pages are Symfony Forms whose token would not be checked without `form.type_extension.csrf` (or with
 * `framework.form.csrf_protection: false`), and the upload endpoint checks its header token with the token manager.
 */
final class CsrfProtectionPass implements CompilerPassInterface
{
    /**
     * Throws when the pages or the upload endpoint are registered and CSRF is unavailable.
     */
    public function process(ContainerBuilder $container): void
    {
        $pages  = $container->hasDefinition(StorageSettingsController::class);
        $upload = $container->hasDefinition(FileUploadController::class);
        if (!$pages && !$upload) {
            return;
        }
        if (!$container->has('security.csrf.token_manager')) {
            throw new LogicException('NowoStorageKitBundle: the storage pages and the upload endpoint require CSRF protection. Enable framework.csrf_protection (composer require symfony/security-csrf), or set nowo_storage_kit.web_ui.enabled and nowo_storage_kit.upload.enabled to false.');
        }
        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;
        if ($pages && (!$container->hasDefinition('form.type_extension.csrf') || $formCsrfDisabled)) {
            throw new LogicException('NowoStorageKitBundle: the storage pages (web_ui.enabled: true) require form CSRF protection. Enable framework.form.csrf_protection, or set nowo_storage_kit.web_ui.enabled: false.');
        }
    }
}
