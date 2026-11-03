<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Routing;

use Nowo\StorageKitBundle\Controller\FileDownloadController;
use Nowo\StorageKitBundle\Controller\FileUploadController;
use Nowo\StorageKitBundle\Controller\StorageMoveController;
use Nowo\StorageKitBundle\Controller\StorageSettingsController;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function is_string;
use function rtrim;
use function str_contains;

/**
 * Route loader `nowo_storage_kit` (REQ-SF-004 rule 7: the only way to expose the bundle routes). Reads the `#[Route]`
 * attributes of the bundle controllers and mounts them, leaving out the groups that are switched off:
 *
 * - instance Storage / Move pages under `web_ui.path_prefix` (with `web_ui.enabled`);
 * - scope Storage / Move pages under `scopes.path_prefix` (with `web_ui.enabled` and `scopes.enabled`);
 * - the upload / download endpoints under `upload.path_prefix` (with `upload.enabled`).
 *
 * ```yaml
 * # config/routes/nowo_storage_kit.yaml
 * nowo_storage_kit:
 *     resource: .
 *     type: nowo_storage_kit
 * ```
 */
final class StorageKitRouteLoader extends Loader
{
    /** Loader type. */
    public const TYPE = 'nowo_storage_kit';

    private bool $loaded = false;

    /**
     * @param string $adminPrefix `web_ui.path_prefix`
     * @param string $scopePrefix `scopes.path_prefix`
     * @param string $filesPrefix `upload.path_prefix`
     */
    public function __construct(
        private readonly bool $webUiEnabled,
        private readonly string $adminPrefix,
        private readonly bool $scopesEnabled,
        private readonly string $scopePrefix,
        private readonly bool $filesEnabled,
        private readonly string $filesPrefix,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    /**
     * Builds the collection (once).
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException('Do not add the "nowo_storage_kit" route loader twice.');
        }
        // @igor-ignore - Route loader builds routes at cache warm-up; not per-request state.
        $this->loaded = true;

        $collection = new RouteCollection();
        foreach ([StorageSettingsController::class, StorageMoveController::class, FileUploadController::class, FileDownloadController::class] as $controller) {
            foreach ((new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
                    $declared = $attribute->newInstance();
                    $name     = (string) $declared->name;
                    $prefix   = $this->prefix($controller, $name);
                    if ($prefix === null) {
                        continue;
                    }
                    $collection->add($name, new Route(
                        $prefix . (is_string($declared->path) ? $declared->path : ''),
                        ['_controller' => $controller . '::' . $method->getName()],
                        $declared->requirements,
                        methods: $declared->methods,
                    ));
                }
            }
        }

        return $collection;
    }

    /**
     * Type `nowo_storage_kit`.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }

    /**
     * The mount prefix of a route, or null when its group is off.
     */
    private function prefix(string $controller, string $name): ?string
    {
        if ($controller === FileUploadController::class || $controller === FileDownloadController::class) {
            return $this->filesEnabled ? rtrim($this->filesPrefix, '/') : null;
        }
        if (!$this->webUiEnabled) {
            return null;
        }
        if (str_contains($name, '_scope_')) {
            return $this->scopesEnabled ? rtrim($this->scopePrefix, '/') : null;
        }

        return rtrim($this->adminPrefix, '/');
    }
}
