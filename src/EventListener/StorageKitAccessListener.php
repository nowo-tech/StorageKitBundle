<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\EventListener;

use Nowo\StorageKitBundle\Controller\StorageMoveController;
use Nowo\StorageKitBundle\Controller\StorageSettingsController;
use Nowo\StorageKitBundle\Security\AccessGuard;
use Symfony\Component\HttpKernel\Event\RequestEvent;

use function in_array;
use function is_string;
use function ltrim;
use function rtrim;
use function str_starts_with;

/**
 * REQ-UI-002 layer 2: denies every request under `web_ui.path_prefix` (the instance Storage / Move pages) unless the
 * access checker grants the current user, and any instance-page route reached another way (a sub-request). Runs after
 * the firewall (priority 8) and before the controller; scope pages are checked by their controller (404) and the file
 * endpoints by their context.
 */
final readonly class StorageKitAccessListener
{
    /** Listener priority (after the firewall, 8). */
    public const PRIORITY = 7;

    /** Instance-page routes. */
    public const INSTANCE_ROUTES = [
        StorageSettingsController::ROUTE,
        StorageSettingsController::ROUTE_TEST,
        StorageMoveController::ROUTE,
        StorageMoveController::ROUTE_ACTION,
    ];

    /** Instance-page controllers (sub-requests such as forward() carry no route). */
    public const INSTANCE_CONTROLLERS = [
        StorageSettingsController::class . '::instance',
        StorageSettingsController::class . '::testInstance',
        StorageMoveController::class . '::instance',
        StorageMoveController::class . '::instanceAction',
    ];

    /**
     * @param string $pathPrefix `web_ui.path_prefix`
     */
    public function __construct(
        private AccessGuard $guard,
        private string $pathPrefix,
    ) {
    }

    /**
     * Denies the request when the user may not open the instance pages.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request    = $event->getRequest();
        $route      = $request->attributes->get('_route');
        $controller = $request->attributes->get('_controller');
        $instance   = (is_string($route) && in_array($route, self::INSTANCE_ROUTES, true))
            || (is_string($controller) && in_array(ltrim($controller, '\\'), self::INSTANCE_CONTROLLERS, true));
        $prefix = rtrim($this->pathPrefix, '/');
        $path   = $request->getPathInfo();
        $under  = $event->isMainRequest() && ($path === $prefix || str_starts_with($path, $prefix . '/'));
        if (!$instance && !$under) {
            return;
        }
        if (!$this->guard->isGranted()) {
            $this->guard->deny('Access denied to the storage settings.');
        }
    }
}
