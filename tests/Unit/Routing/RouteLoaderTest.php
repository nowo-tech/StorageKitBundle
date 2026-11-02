<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Routing;

use Nowo\StorageKitBundle\Routing\StorageKitRouteLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RouteLoaderTest extends TestCase
{
    public function testGroupsAndPrefixes(): void
    {
        $all = (new StorageKitRouteLoader(true, '/ops/storage/', true, '/orgs', true, '/media'))->load('.');

        self::assertSame('/ops/storage', $all->get('nowo_storage_kit_settings')?->getPath());
        self::assertSame('/ops/storage/move/{action}', $all->get('nowo_storage_kit_move_action')?->getPath());
        self::assertSame('/orgs/{scope}/move', $all->get('nowo_storage_kit_scope_move')?->getPath());
        self::assertSame('/media/upload/{type}/{id}', $all->get('nowo_storage_kit_upload')?->getPath());
        self::assertSame(['POST'], $all->get('nowo_storage_kit_upload')->getMethods());

        $none = (new StorageKitRouteLoader(false, '/a', true, '/b', false, '/c'))->load('.');
        self::assertCount(0, $none);
        $noScopes = (new StorageKitRouteLoader(true, '/a', false, '/b', false, '/c'))->load('.');
        self::assertNull($noScopes->get('nowo_storage_kit_scope_settings'));
        self::assertNotNull($noScopes->get('nowo_storage_kit_settings'));
        self::assertTrue((new StorageKitRouteLoader(true, '/a', false, '/b', true, '/c'))->supports('.', 'nowo_storage_kit'));
        self::assertFalse((new StorageKitRouteLoader(true, '/a', false, '/b', true, '/c'))->supports('.', 'attribute'));
    }

    public function testTheLoaderRunsOnce(): void
    {
        $loader = new StorageKitRouteLoader(true, '/a', false, '/b', true, '/c');
        $loader->load('.');

        $this->expectException(RuntimeException::class);
        $loader->load('.');
    }
}
