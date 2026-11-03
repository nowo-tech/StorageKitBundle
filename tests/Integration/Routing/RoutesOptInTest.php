<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Routing;

use Nowo\StorageKitBundle\Controller\FileDownloadController;
use Nowo\StorageKitBundle\Controller\FileUploadController;
use Nowo\StorageKitBundle\Controller\StorageMoveController;
use Nowo\StorageKitBundle\Controller\StorageSettingsController;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouterInterface;

use function class_exists;
use function is_string;
use function str_starts_with;

/**
 * REQ-SF-004 rule 7: a host whose routing is only `routing.controllers` (Symfony >= 7.3 default config/routes.yaml)
 * gets no bundle route; the routes exist only through the `nowo_storage_kit` loader import.
 */
final class RoutesOptInTest extends KernelTestCase
{
    public function testRoutingControllersDoesNotMountTheBundleRoutes(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('routing.controllers requires symfony/routing >= 7.3.');
        }

        $kernel = $this->bootKernel(routes: static function (RoutingConfigurator $routes): void {
            $routes->import('routing.controllers');
        });
        foreach ([StorageSettingsController::class, StorageMoveController::class, FileUploadController::class, FileDownloadController::class] as $controller) {
            self::assertTrue($this->container($kernel)->has($controller), $controller);
        }

        /** @var RouterInterface $router */
        $router = $kernel->getContainer()->get('router');
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            self::assertStringStartsNotWith('nowo_storage_kit', $name);
            $controller = $route->getDefault('_controller');
            self::assertFalse(is_string($controller) && str_starts_with($controller, 'Nowo\\StorageKitBundle\\'), $name);
        }
    }

    public function testTheLoaderImportMountsThemUnderThePrefixes(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['path_prefix' => '/ops/storage'], 'upload' => ['path_prefix' => '/media']]);
        /** @var RouterInterface $router */
        $router = $kernel->getContainer()->get('router');
        $routes = $router->getRouteCollection();

        self::assertSame('/ops/storage', $routes->get('nowo_storage_kit_settings')?->getPath());
        self::assertSame('/storage/scopes/{scope}/move/{action}', $routes->get('nowo_storage_kit_scope_move_action')?->getPath());
        self::assertSame('/media/files/{uuid}/{variant}', $routes->get('nowo_storage_kit_file')?->getPath());
    }

    public function testNoPageRouteWithoutTheUi(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['enabled' => false]]);
        /** @var RouterInterface $router */
        $router = $kernel->getContainer()->get('router');

        self::assertNull($router->getRouteCollection()->get('nowo_storage_kit_settings'));
        self::assertNotNull($router->getRouteCollection()->get('nowo_storage_kit_upload'));
        self::assertFalse($this->container($kernel)->has(StorageSettingsController::class));
    }
}
