<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Lifecycle;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Tests\Fixtures\Entity\Note;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploader;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_map;
use function strtolower;

/**
 * 1.8.1 (audit XI-3): the tables the collation listener changes keep their foreign keys on a real database, in host
 * mode (the host's manager) and private mode (`doctrine.entity_manager`), and their rules hold: a file reference goes
 * with its file (ON DELETE CASCADE) and a profile that still has files cannot be deleted. Up to 1.8.0, MySQL / MariaDB
 * with ORM 3.7.0 to 3.8.0 and DBAL 4.5+ got neither foreign key. Runs on SQLite (with `foreign_keys` on), MySQL,
 * MariaDB and PostgreSQL (CI: STORAGE_KIT_TEST_DATABASE_URL).
 */
final class SchemaForeignKeysTest extends KernelTestCase
{
    /** @return iterable<string, array{bool}> */
    public static function modes(): iterable
    {
        yield 'host mode' => [false];
        yield 'private mode' => [true];
    }

    #[DataProvider('modes')]
    public function testTheCreatedSchemaHasTheForeignKeys(bool $private): void
    {
        $schemaManager = $this->connection($this->boot($private))->createSchemaManager();

        $profile = $this->foreignKey($schemaManager->listTableForeignKeys('storage_kit_file'), 'profile_id');
        self::assertSame('storage_kit_profile', strtolower($profile->getForeignTableName()));
        $file = $this->foreignKey($schemaManager->listTableForeignKeys('storage_kit_file_reference'), 'file_id');
        self::assertSame('storage_kit_file', strtolower($file->getForeignTableName()));
        self::assertSame('CASCADE', $file->onDelete());
    }

    #[DataProvider('modes')]
    public function testTheRulesHold(bool $private): void
    {
        $kernel     = $this->boot($private);
        $connection = $this->connection($kernel);
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            // SQLite enforces foreign keys (and their ON DELETE actions) only when asked to.
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        }
        $profile = $this->profile($kernel);
        $file    = $this->service($kernel, ImageUploader::class)->upload(new InMemoryUser('alice', null), 'board', 'b1', Images::png(2000, 1000), 'photo.png');
        self::assertInstanceOf(StoredFile::class, $file);
        $note    = new Note('b1', '![a](storage-file:' . $file->getUuid() . ')');
        $manager = $this->entityManager($kernel);
        $manager->persist($note);
        $manager->flush();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM storage_kit_file_reference WHERE file_id = ?', [$file->getId()]));

        // A profile that still has files cannot be deleted.
        try {
            $connection->executeStatement('DELETE FROM storage_kit_profile WHERE id = ?', [$profile->getId()]);
            self::fail('A profile with files was deleted');
        } catch (ForeignKeyConstraintViolationException) {
        }
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM storage_kit_profile WHERE id = ?', [$profile->getId()]));

        // The reference goes with its file.
        $connection->executeStatement('DELETE FROM storage_kit_file WHERE id = ?', [$file->getId()]);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM storage_kit_file_reference'));
    }

    private function boot(bool $private): StorageKitTestKernel
    {
        if (!$private) {
            return $this->bootKernel();
        }

        return $this->bootKernel(['doctrine' => ['entity_manager' => 'storage_kit']], orm: [
            'default_entity_manager' => 'default',
            'entity_managers'        => [
                'default'     => ['connection' => 'default', 'auto_mapping' => true, 'mappings' => ['Tests' => StorageKitTestKernel::testsMapping()]],
                'storage_kit' => ['connection' => 'default'],
            ],
        ]);
    }

    /** The bundle manager's connection (host mode: the host's). */
    private function connection(StorageKitTestKernel $kernel): Connection
    {
        return $this->service($kernel, StorageEntityManager::class)->manager()->getConnection();
    }

    /**
     * @param array<ForeignKeyConstraint> $foreignKeys
     */
    private function foreignKey(array $foreignKeys, string $column): ForeignKeyConstraint
    {
        foreach ($foreignKeys as $foreignKey) {
            if (array_map(strtolower(...), $foreignKey->getLocalColumns()) === [$column]) {
                return $foreignKey;
            }
        }
        self::fail('No foreign key on ' . $column);
    }
}
