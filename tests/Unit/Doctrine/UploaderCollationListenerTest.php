<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Doctrine\UploaderCollationListener;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Tests\Support\OfflineSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

final class UploaderCollationListenerTest extends TestCase
{
    public function testTheCollationPerPlatform(): void
    {
        self::assertSame('utf8mb4_nopad_bin', UploaderCollationListener::collationFor(new MariaDBPlatform()));
        self::assertSame('utf8mb4_0900_bin', UploaderCollationListener::collationFor(new MySQL80Platform()));
        self::assertSame('utf8mb4_bin', UploaderCollationListener::collationFor(new MySQLPlatform()));
        self::assertNull(UploaderCollationListener::collationFor(new PostgreSQLPlatform()));
        self::assertNull(UploaderCollationListener::collationFor(new SQLitePlatform()));
    }

    public function testAFullMysqlServerVersionOlderThan8017FallsBackToUtf8mb4Bin(): void
    {
        self::assertSame('utf8mb4_bin', UploaderCollationListener::collationFor(new MySQL80Platform(), '8.0.16'));
        self::assertSame('utf8mb4_0900_bin', UploaderCollationListener::collationFor(new MySQL80Platform(), '8.0.17'));
        self::assertSame('utf8mb4_0900_bin', UploaderCollationListener::collationFor(new MySQL80Platform(), '8.4'));
        self::assertSame('utf8mb4_0900_bin', UploaderCollationListener::collationFor(new MySQL80Platform()));
        self::assertSame('utf8mb4_nopad_bin', UploaderCollationListener::collationFor(new MariaDBPlatform(), '10.4.1-MariaDB'));
    }

    public function testTheConfiguredServerVersion(): void
    {
        self::assertSame('8.0.36', UploaderCollationListener::configuredServerVersion(['serverVersion' => '8.0.36']));
        self::assertSame('8.0', UploaderCollationListener::configuredServerVersion(['primary' => ['serverVersion' => '8.0']]));
        self::assertNull(UploaderCollationListener::configuredServerVersion(['serverVersion' => '']));
        self::assertNull(UploaderCollationListener::configuredServerVersion(['primary' => 'x']));
        self::assertNull(UploaderCollationListener::configuredServerVersion([]));
    }

    public function testTheFallbackIsLoggedWithTheServerVersion(): void
    {
        $logger = new BufferingLogger();
        $schema = OfflineSchema::generate('mysql://u@127.0.0.1/storagekit?serverVersion=8.0', new UploaderCollationListener(null, $logger));
        self::assertSame('utf8mb4_bin', $schema->getTable('storage_kit_file_reference')->getColumn('source_id')->getPlatformOption('collation'));
        $logs = $logger->cleanLogs();
        self::assertCount(3, $logs, 'one warning per collated table');
        self::assertSame('warning', $logs[0][0]);
        self::assertSame(['table' => 'storage_kit_file', 'version' => '8.0'], $logs[0][2]);

        // DBAL detected the platform itself (no server_version): the 5.7 platform falls back too.
        $args = $this->args(new MySQLPlatform());
        (new UploaderCollationListener(null, $logger))->postGenerateSchema($args);
        self::assertSame('utf8mb4_bin', $args->getSchema()->getTable('storage_kit_profile')->getColumn('scope')->getPlatformOption('collation'));
        self::assertSame('detected', $logger->cleanLogs()[0][2]['version']);

        OfflineSchema::generate('mysql://u@127.0.0.1/storagekit?serverVersion=11.4.0-MariaDB', new UploaderCollationListener(null, $logger));
        self::assertSame([], $logger->cleanLogs(), 'a NO PAD collation logs nothing');
    }

    public function testTheIdentityColumnsGetTheCollationOnMysql(): void
    {
        $schema = OfflineSchema::generate('mysql://u@127.0.0.1/storagekit?serverVersion=8.4.0', new UploaderCollationListener());
        $file   = $schema->getTable('storage_kit_file');

        foreach (['uploaded_by', 'uploaded_by_key', 'context_id', 'owner'] as $column) {
            self::assertSame('utf8mb4_0900_bin', $file->getColumn($column)->getPlatformOption('collation'));
            self::assertSame('utf8mb4', $file->getColumn($column)->getPlatformOption('charset'));
        }
        self::assertFalse($file->getColumn('original_name')->hasPlatformOption('collation'));
        self::assertSame('utf8mb4_0900_bin', $schema->getTable('storage_kit_file_reference')->getColumn('source_id')->getPlatformOption('collation'));
        self::assertSame('utf8mb4_0900_bin', $schema->getTable('storage_kit_profile')->getColumn('scope')->getPlatformOption('collation'));
        foreach ($schema->getTable('storage_kit_sync')->getColumns() as $column) {
            self::assertFalse($column->hasPlatformOption('collation'));
        }
    }

    public function testExactPlatformsAreUntouched(): void
    {
        $args   = $this->args(new PostgreSQLPlatform());
        $schema = $args->getSchema();
        (new UploaderCollationListener())->postGenerateSchema($args);
        self::assertSame($schema, $args->getSchema());
        self::assertFalse($schema->getTable('storage_kit_file')->getColumn('uploaded_by')->hasPlatformOption('collation'));

        $schema = OfflineSchema::generate('sqlite:///:memory:', new UploaderCollationListener());
        self::assertFalse($schema->getTable('storage_kit_file')->getColumn('uploaded_by')->hasPlatformOption('collation'));
    }

    /** A manager that does not map the bundle (the host's, with a dedicated `doctrine.entity_manager`) is left alone. */
    public function testAManagerWithoutTheBundleIsLeftAlone(): void
    {
        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $metadataFactory->method('isTransient')->willReturn(true);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getMetadataFactory')->willReturn($metadataFactory);
        $manager->expects(self::never())->method('getConnection');
        $schema = new Schema();
        $args   = new GenerateSchemaEventArgs($manager, $schema);

        (new UploaderCollationListener())->postGenerateSchema($args);

        self::assertSame($schema, $args->getSchema());
    }

    /** A schema without the bundle tables (a subset of the classes) is left as it is. */
    public function testASchemaWithoutTheBundleTablesIsLeftAsItIs(): void
    {
        $schema = new Schema();
        $args   = new GenerateSchemaEventArgs(OfflineSchema::manager('mysql://u@127.0.0.1/storagekit?serverVersion=8.4.0'), $schema);

        (new UploaderCollationListener())->postGenerateSchema($args);

        self::assertSame($schema, $args->getSchema());
    }

    /** The 1.5.2–1.8.0 constructor argument is still accepted (unused). */
    public function testTheFormerConstructorArgumentIsAccepted(): void
    {
        $schema = OfflineSchema::generate('mysql://u@127.0.0.1/storagekit?serverVersion=11.4.0-MariaDB', new UploaderCollationListener(new StorageEntityManager($this->createStub(ManagerRegistry::class))));

        self::assertSame('utf8mb4_nopad_bin', $schema->getTable('storage_kit_file')->getColumn('uploaded_by')->getPlatformOption('collation'));
    }

    public function testCollatesInPlaceOrThroughTheEditor(): void
    {
        $table = new Table('t');
        $table->addColumn('uploaded_by', 'string', ['length' => 180]);
        self::assertNull(UploaderCollationListener::collate($table, 'uploaded_by', 'utf8mb4_nopad_bin', false));
        self::assertSame('utf8mb4_nopad_bin', $table->getColumn('uploaded_by')->getPlatformOption('collation'));

        $edited = UploaderCollationListener::collate($table, 'uploaded_by', 'utf8mb4_0900_bin', true);
        self::assertInstanceOf(Table::class, $edited);
        self::assertNotSame($table, $edited);
        self::assertSame('utf8mb4_0900_bin', $edited->getColumn('uploaded_by')->getPlatformOption('collation'));
    }

    /**
     * The schema of the bundle entities (SQLite metadata) behind a manager whose connection has `$platform` and no
     * configured server_version (DBAL detected the platform).
     */
    private function args(AbstractPlatform $platform): GenerateSchemaEventArgs
    {
        $real       = OfflineSchema::manager('sqlite:///:memory:');
        $schema     = (new SchemaTool($real))->getSchemaFromMetadata($real->getMetadataFactory()->getAllMetadata());
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getParams')->willReturn([]);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getMetadataFactory')->willReturn($real->getMetadataFactory());
        $manager->method('getClassMetadata')->willReturnCallback($real->getClassMetadata(...));
        $manager->method('getConnection')->willReturn($connection);

        return new GenerateSchemaEventArgs($manager, $schema);
    }
}
