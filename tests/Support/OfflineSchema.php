<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Support;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\StorageKitBundle\Doctrine\UploaderCollationListener;

use function dirname;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * 1.8.1 (audit XI-3): an entity manager on the bundle entities for the platform a database URL names, never connected
 * to (`serverVersion` decides the platform; `sqlite:///:memory:` for SQLite), and the schema the ORM schema tool
 * generates for it.
 */
final class OfflineSchema
{
    public static function manager(string $url): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity'], true, sys_get_temp_dir() . '/nowo-storage-kit-schema-proxies');
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'sqlite' => 'pdo_sqlite']))->parse($url);

        return new EntityManager(DriverManager::getConnection($params, $config), $config, new EventManager());
    }

    /**
     * The schema of every bundle entity, with the collation listener when given.
     */
    public static function generate(string $url, ?UploaderCollationListener $listener = null): Schema
    {
        $manager = self::manager($url);
        if ($listener instanceof UploaderCollationListener) {
            $manager->getEventManager()->addEventListener(ToolEvents::postGenerateSchema, $listener);
        }

        return (new SchemaTool($manager))->getSchemaFromMetadata($manager->getMetadataFactory()->getAllMetadata());
    }
}
