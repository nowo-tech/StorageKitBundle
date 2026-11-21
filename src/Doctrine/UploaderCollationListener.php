<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Entity\StoredFileReference;
use Nowo\StorageKitBundle\Identifier\ExactIdentifier;
use Nowo\StorageKitBundle\PersonalData\UploaderIdentity;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function array_map;
use function is_array;
use function is_string;
use function method_exists;
use function preg_match;
use function version_compare;

/**
 * Exact-match collation for the identifier columns of the bundle on MySQL / MariaDB (REQ-SEC-006):
 * `storage_kit_file.uploaded_by` and `uploaded_by_key` (1.5.2, house rule LQ), then `storage_kit_file.context_id` and
 * `owner`, `storage_kit_file_reference.source_id` and `storage_kit_profile.scope` (1.5.3). The class keeps its 1.5.2
 * name.
 *
 * The host default collations fold case and accents (`utf8mb4_0900_ai_ci`, `utf8mb4_unicode_ci`, MariaDB
 * `utf8mb4_uca1400_ai_ci`) and most are PAD SPACE (trailing spaces ignored), so `alice` = `Alice` = `álice` = `alice `.
 * Every lookup already re-checks its rows with `===` ({@see UploaderIdentity}, {@see ExactIdentifier}); the binary NO
 * PAD collation keeps the database itself exact: MariaDB `utf8mb4_nopad_bin`, MySQL 8.0.17+ `utf8mb4_0900_bin`, and
 * `utf8mb4_bin` (PAD SPACE, the only binary one older servers have) as the fallback, with a logged warning: MySQL 5.7, a
 * MySQL 8.0 older than 8.0.17, or a `server_version` without the patch number (`8`, `8.0`: DBAL then picks its MySQL 5.7
 * platform). PostgreSQL and SQLite compare exactly and are untouched (they reject MySQL collation names, so it cannot
 * live in the mapping). Applied to the generated schema (schema tool, `doctrine:migrations:diff`); existing tables need
 * the `ALTER TABLE` of UPGRADING.md.
 *
 * 1.8.1 (audit XI-3): applied on `postGenerateSchema`, once the schema tool has added every foreign key, by table name.
 * 1.5.2–1.8.0 replaced each table on `postGenerateSchemaTable` (`setClassTable()`, DBAL 4.5+), and the ORM schema tool
 * (3.7.0 to 3.8.0) then added the table's foreign keys to the table it had replaced: on MySQL / MariaDB the generated
 * schema lost `storage_kit_file.profile_id` and `storage_kit_file_reference.file_id` (ON DELETE CASCADE); with ORM
 * before 3.7 and DBAL 4.5+, `setClassTable()` did not exist (audit XI-8). Now:
 *
 * - DBAL 4.5+ with `GenerateSchemaEventArgs::setSchema()` (ORM 3.7+): each table is rebuilt with its table editor
 *   (foreign keys and indexes included) and swapped into a new schema given back with `setSchema()`;
 * - otherwise: the columns' platform options are set in place (the DBAL 4.5+ deprecation silenced).
 *
 * 2.0 removed the no-op `postGenerateSchemaTable()` kept by 1.8.1 (deprecated there).
 *
 * Stateless: safe in FrankenPHP worker mode.
 */
final readonly class UploaderCollationListener
{
    /** @var list<string> the uploader fields of {@see StoredFile} (1.5.2; {@see self::COLUMNS} lists every field) */
    public const FIELDS = ['uploadedBy', 'uploadedByKey'];

    /** @var array<class-string, list<string>> entity => the mapped fields that get the collation */
    public const COLUMNS = [
        StoredFile::class          => ['uploadedBy', 'uploadedByKey', 'contextId', 'owner'],
        StoredFileReference::class => ['sourceId'],
        StorageProfile::class      => ['scope'],
    ];

    /** First MySQL release with `utf8mb4_0900_bin`. */
    public const MYSQL_0900_BIN = '8.0.17';

    /**
     * Where the fallback warning goes.
     *
     * @param StorageEntityManager|null $entityManager unused since 1.8.1 (the platform comes from the schema tool's entity
     *                                                 manager); kept for BC
     */
    public function __construct(
        ?StorageEntityManager $entityManager = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        unset($entityManager);
    }

    /**
     * The exact-match collation on that platform, null when its default is already exact. `$serverVersion` is the
     * configured `server_version` (null when DBAL detected the platform from the server itself): a full MySQL version
     * older than {@see self::MYSQL_0900_BIN} gets `utf8mb4_bin`.
     *
     * @return non-empty-string|null
     */
    public static function collationFor(AbstractPlatform $platform, ?string $serverVersion = null): ?string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform       => 'utf8mb4_nopad_bin',
            $platform instanceof MySQL80Platform       => self::before0900Bin($serverVersion) ? 'utf8mb4_bin' : 'utf8mb4_0900_bin',
            $platform instanceof AbstractMySQLPlatform => 'utf8mb4_bin',
            default                                    => null,
        };
    }

    /**
     * Doctrine `postGenerateSchema` listener: gives the identifier columns of the bundle tables their exact-match
     * collation in the generated schema, keeping every foreign key and index of those tables.
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $entityManager = $args->getEntityManager();
        // Only the schema of a manager that maps the bundle (with a dedicated manager, the host's is left alone).
        if ($entityManager->getMetadataFactory()->isTransient(StoredFile::class)) {
            return;
        }
        $connection    = $entityManager->getConnection();
        $serverVersion = self::configuredServerVersion($connection->getParams());
        $collation     = self::collationFor($connection->getDatabasePlatform(), $serverVersion);
        if ($collation === null) {
            return;
        }

        $schema = $args->getSchema();
        /** @var array<non-empty-string, list<non-empty-string>> $tables table name => the columns to collate */
        $tables = [];
        foreach (self::COLUMNS as $class => $fields) {
            $metadata  = $entityManager->getClassMetadata($class);
            $tableName = $metadata->getTableName();
            if ($tableName === '' || !$schema->hasTable($tableName)) {
                continue;
            }
            /** @var list<non-empty-string> $columns mapped column names are never empty */
            $columns            = array_map($metadata->getColumnName(...), $fields);
            $tables[$tableName] = $columns;
            if ($collation === 'utf8mb4_bin') {
                $this->logger->warning('Storage kit: the identifier columns of {table} get utf8mb4_bin, a PAD SPACE collation ("bob " = "bob"): the server is MySQL 5.7 or older than 8.0.17, or server_version "{version}" has no patch number. Set the full server_version (for example 8.0.36) on MySQL 8.', [
                    'table'   => $tableName,
                    'version' => $serverVersion ?? 'detected',
                ]);
            }
        }

        // Table::edit() and Schema::edit() exist from DBAL 4.5, GenerateSchemaEventArgs::setSchema() from ORM 3.7.
        // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType, function.alreadyNarrowedType
        $editor = method_exists(Table::class, 'edit') && method_exists(Schema::class, 'edit') && method_exists($args, 'setSchema');
        $edited = [];
        foreach ($tables as $tableName => $columns) {
            $table = $schema->getTable($tableName);
            foreach ($columns as $column) {
                $table = self::collate($table, $column, $collation, $editor) ?? $table;
            }
            $edited[$tableName] = $table;
        }
        if ($editor && $edited !== []) {
            $schemaEditor = $schema->edit();
            foreach ($edited as $tableName => $table) {
                $schemaEditor->dropTableByUnquotedName($tableName)->addTable($table);
            }
            $args->setSchema($schemaEditor->create());
        }
    }

    /**
     * Gives `$column` the utf8mb4 charset and `$collation`: through a table editor (DBAL 4.5+; returns the new table) or
     * in place (returns null; the deprecation DBAL 4.5+ raises for it is silenced, for ORM versions without
     * `GenerateSchemaEventArgs::setSchema()`).
     *
     * @param non-empty-string $column
     * @param non-empty-string $collation
     */
    public static function collate(Table $table, string $column, string $collation, bool $editor): ?Table
    {
        if ($editor) {
            return $table->edit()->modifyColumnByUnquotedName($column, static function (ColumnEditor $columnEditor) use ($collation): void {
                $columnEditor->setCharset('utf8mb4')->setCollation($collation);
            })->create();
        }

        $target = $table->getColumn($column);
        @$target->setPlatformOption('charset', 'utf8mb4');
        @$target->setPlatformOption('collation', $collation);

        return null;
    }

    /**
     * The `server_version` the connection was configured with (`serverVersion`, or the primary's), null when none.
     *
     * @param array<string, mixed> $params
     */
    public static function configuredServerVersion(array $params): ?string
    {
        $version = $params['serverVersion'] ?? (is_array($params['primary'] ?? null) ? ($params['primary']['serverVersion'] ?? null) : null);

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * Whether a full MySQL version (`x.y.z`) is older than {@see self::MYSQL_0900_BIN}; a version without patch number
     * selects the MySQL 5.7 platform in DBAL already, and none means DBAL read the real version from the server.
     */
    private static function before0900Bin(?string $serverVersion): bool
    {
        if ($serverVersion === null || preg_match('/^(\d+)\.(\d+)\.(\d+)/', $serverVersion, $match) !== 1) {
            return false;
        }

        return version_compare($match[1] . '.' . $match[2] . '.' . $match[3], self::MYSQL_0900_BIN, '<');
    }
}
