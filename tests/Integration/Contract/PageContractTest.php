<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Contract;

use Nowo\StorageKitBundle\Controller\StorageSettingsController;
use Nowo\StorageKitBundle\Tests\Support\PageFixtures;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;

use function dirname;

/**
 * REQ-UI-003 page contract: the Storage and Move pages extend the UiKit form scaffold; no <html>, CDN, inline handler
 * or confirm(); framework classes only through theme slots; the stub theme is reflected; no raw or missing
 * translation (strict rule); no secret is rendered.
 */
final class PageContractTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        yield 'storage (instance)' => new PageFixture('@NowoStorageKitBundle/settings/edit.html.twig', PageFixtures::settings());
        yield 'storage (scope)' => new PageFixture('@NowoStorageKitBundle/settings/edit.html.twig', PageFixtures::settings('acme'));
        yield 'storage (configuration-managed)' => new PageFixture('@NowoStorageKitBundle/settings/edit.html.twig', PageFixtures::settings(null, StorageSettingsController::MANAGED_CONFIG));
        yield 'storage (instance settings)' => new PageFixture('@NowoStorageKitBundle/settings/edit.html.twig', PageFixtures::settings(null, StorageSettingsController::MANAGED_SETTINGS));
        yield 'move (idle)' => new PageFixture('@NowoStorageKitBundle/move/edit.html.twig', PageFixtures::move());
        yield 'move (running)' => new PageFixture('@NowoStorageKitBundle/move/edit.html.twig', PageFixtures::move(true));
    }

    public function testNoSecretIsRendered(): void
    {
        foreach ([PageFixtures::settings(), PageFixtures::move(true)] as $context) {
            $html = $this->renderPage(new PageFixture('@NowoStorageKitBundle/settings/edit.html.twig', PageFixtures::settings()))->html;
            self::assertStringNotContainsString('test-secret-not-real', $html);
            self::assertArrayHasKey('page', $context);
        }
    }

    protected static function templatePaths(): array
    {
        return ['NowoStorageKitBundle' => dirname(__DIR__, 3) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }
}
