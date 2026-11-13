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
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Doctrine\UploaderCollationListener;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Entity\StoredFileReference;
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
        $args   = $this->args(StoredFileReference::class);
        (new UploaderCollationListener($this->manager(new MySQLPlatform(), ['serverVersion' => '8.0']), $logger))->postGenerateSchemaTable($args);
        self::assertSame('utf8mb4_bin', $args->getClassTable()->getColumn('source_id')->getPlatformOption('collation'));
        $logs = $logger->cleanLogs();
        self::assertCount(1, $logs);
        self::assertSame('warning', $logs[0][0]);
        self::assertSame(['table' => 'storage_kit_file', 'version' => '8.0'], $logs[0][2]);

        $args = $this->args(StorageProfile::class);
        (new UploaderCollationListener($this->manager(new MySQLPlatform()), $logger))->postGenerateSchemaTable($args);
        self::assertSame('utf8mb4_bin', $args->getClassTable()->getColumn('scope')->getPlatformOption('collation'));
        self::assertSame('detected', $logger->cleanLogs()[0][2]['version']);

        $args = $this->args(StoredFile::class);
        (new UploaderCollationListener($this->manager(new MariaDBPlatform()), $logger))->postGenerateSchemaTable($args);
        self::assertSame([], $logger->cleanLogs(), 'a NO PAD collation logs nothing');
    }

    public function testTheIdentityColumnsGetTheCollationOnMysql(): void
    {
        $args = $this->args(StoredFile::class);
        (new UploaderCollationListener($this->manager(new MySQL80Platform())))->postGenerateSchemaTable($args);

        foreach (['uploaded_by', 'uploaded_by_key', 'context_id', 'owner'] as $column) {
            self::assertSame('utf8mb4_0900_bin', $args->getClassTable()->getColumn($column)->getPlatformOption('collation'));
            self::assertSame('utf8mb4', $args->getClassTable()->getColumn($column)->getPlatformOption('charset'));
        }
        self::assertFalse($args->getClassTable()->getColumn('original_name')->hasPlatformOption('collation'));
    }

    public function testOtherEntitiesAndExactPlatformsAreUntouched(): void
    {
        $args  = $this->args(StorageSync::class);
        $table = $args->getClassTable();
        (new UploaderCollationListener($this->manager(new MySQL80Platform())))->postGenerateSchemaTable($args);
        self::assertSame($table, $args->getClassTable());

        foreach ([StoredFileReference::class => 'source_id', StorageProfile::class => 'scope'] as $class => $column) {
            $args = $this->args($class);
            (new UploaderCollationListener($this->manager(new MySQL80Platform())))->postGenerateSchemaTable($args);
            self::assertSame('utf8mb4_0900_bin', $args->getClassTable()->getColumn($column)->getPlatformOption('collation'));
        }

        $args  = $this->args(StoredFile::class);
        $table = $args->getClassTable();
        (new UploaderCollationListener($this->manager(new PostgreSQLPlatform())))->postGenerateSchemaTable($args);
        self::assertSame($table, $args->getClassTable());
        self::assertFalse($table->getColumn('uploaded_by')->hasPlatformOption('collation'));
    }

    public function testTheInPlaceBranchOfOlderDbal(): void
    {
        $table = new Table('t');
        $table->addColumn('uploaded_by', 'string', ['length' => 180]);
        self::assertNull(UploaderCollationListener::collate($table, 'uploaded_by', 'utf8mb4_nopad_bin', false));
        self::assertSame('utf8mb4_nopad_bin', $table->getColumn('uploaded_by')->getPlatformOption('collation'));
    }

    /**
     * @param class-string $class
     */
    private function args(string $class): GenerateSchemaTableEventArgs
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable(['name' => 'storage_kit_file']);
        $table = new Table('storage_kit_file');
        foreach (['uploadedBy' => 'uploaded_by', 'uploadedByKey' => 'uploaded_by_key', 'originalName' => 'original_name', 'contextId' => 'context_id', 'owner' => 'owner', 'sourceId' => 'source_id', 'scope' => 'scope'] as $field => $column) {
            $metadata->mapField(['fieldName' => $field, 'columnName' => $column, 'type' => 'string', 'length' => 180, 'nullable' => true]);
            $table->addColumn($column, 'string', ['length' => 180, 'notnull' => false]);
        }

        return new GenerateSchemaTableEventArgs($metadata, new Schema(), $table);
    }

    /**
     * @param array<string, mixed> $params connection parameters
     */
    private function manager(AbstractPlatform $platform, array $params = []): StorageEntityManager
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('getParams')->willReturn($params);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        return new StorageEntityManager($registry);
    }
}
