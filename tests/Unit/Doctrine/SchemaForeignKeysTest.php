<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Schema\Schema;
use Nowo\StorageKitBundle\Doctrine\UploaderCollationListener;
use Nowo\StorageKitBundle\Tests\Support\OfflineSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function implode;
use function ksort;
use function sort;
use function strtolower;

/**
 * 1.8.1 (audit XI-3): the collation listener must not change the foreign keys or indexes of the generated schema.
 *
 * Up to 1.8.0 the listener rebuilt the table through the DBAL 4.5+ editor and handed it back with setClassTable();
 * ORM 3.7.0 to 3.8.0 then added the deferred foreign keys to the table it had replaced, so on MySQL / MariaDB
 * `storage_kit_file.profile_id` (1.5.2+) and `storage_kit_file_reference.file_id` (ON DELETE CASCADE, 1.5.3+) had no
 * foreign key. The servers are never connected to: the `serverVersion` decides the platform.
 */
final class SchemaForeignKeysTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function platforms(): iterable
    {
        yield 'MySQL 8.4' => ['mysql://u@127.0.0.1/storagekit?serverVersion=8.4.0'];
        yield 'MySQL 8.0.16' => ['mysql://u@127.0.0.1/storagekit?serverVersion=8.0.16'];
        yield 'MySQL 5.7' => ['mysql://u@127.0.0.1/storagekit?serverVersion=5.7.44'];
        yield 'MariaDB 11.4' => ['mysql://u@127.0.0.1/storagekit?serverVersion=11.4.0-MariaDB'];
    }

    #[DataProvider('platforms')]
    public function testTheListenerKeepsEveryForeignKeyAndIndex(string $url): void
    {
        self::assertSame($this->describe($this->schema($url, false)), $this->describe($this->schema($url, true)));
    }

    #[DataProvider('platforms')]
    public function testTheAuditedForeignKeysAreGenerated(string $url): void
    {
        $schema = $this->schema($url, true);
        $tables = $this->describe($schema);

        self::assertContains('profile_id -> storage_kit_profile(id)', $tables['storage_kit_file']['foreign keys']);
        self::assertContains('file_id -> storage_kit_file(id) ON DELETE CASCADE', $tables['storage_kit_file_reference']['foreign keys']);
        // The listener did collate those tables.
        self::assertNotNull($schema->getTable('storage_kit_file')->getColumn('uploaded_by')->getPlatformOptions()['collation'] ?? null);
        self::assertNotNull($schema->getTable('storage_kit_file_reference')->getColumn('source_id')->getPlatformOptions()['collation'] ?? null);
    }

    private function schema(string $url, bool $listener): Schema
    {
        return OfflineSchema::generate($url, $listener ? new UploaderCollationListener() : null);
    }

    /**
     * Every table's foreign keys (columns, target, ON DELETE) and indexes (name, columns, unique).
     *
     * @return array<string, array{'foreign keys': list<string>, indexes: list<string>}>
     */
    private function describe(Schema $schema): array
    {
        $tables = [];
        foreach ($schema->getTables() as $table) {
            $foreignKeys = [];
            foreach ($table->getForeignKeys() as $foreignKey) {
                $foreignKeys[] = implode(', ', $foreignKey->getLocalColumns()) . ' -> ' . $foreignKey->getForeignTableName() . '(' . implode(', ', $foreignKey->getForeignColumns()) . ')' . ($foreignKey->onDelete() !== null ? ' ON DELETE ' . $foreignKey->onDelete() : '');
            }
            $indexes = [];
            foreach ($table->getIndexes() as $index) {
                $indexes[] = strtolower($index->getName()) . ($index->isUnique() ? ' UNIQUE' : '') . ' (' . implode(', ', $index->getColumns()) . ')';
            }
            sort($foreignKeys);
            sort($indexes);
            $tables[$table->getName()] = ['foreign keys' => $foreignKeys, 'indexes' => $indexes];
        }
        ksort($tables);

        return $tables;
    }
}
