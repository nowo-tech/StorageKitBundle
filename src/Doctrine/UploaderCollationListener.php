<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Entity\StoredFileReference;
use Nowo\StorageKitBundle\Identifier\ExactIdentifier;
use Nowo\StorageKitBundle\PersonalData\UploaderIdentity;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
     * The bundle's entity manager (its connection decides the platform) and where the fallback warning goes.
     */
    public function __construct(
        private StorageEntityManager $entityManager,
        private LoggerInterface $logger = new NullLogger(),
    ) {
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
     * Doctrine `postGenerateSchemaTable` listener.
     */
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $fields   = self::COLUMNS[$metadata->getName()] ?? null;
        if ($fields === null) {
            return;
        }
        $connection    = $this->entityManager->manager()->getConnection();
        $serverVersion = self::configuredServerVersion($connection->getParams());
        $collation     = self::collationFor($connection->getDatabasePlatform(), $serverVersion);
        if ($collation === null) {
            return;
        }
        if ($collation === 'utf8mb4_bin') {
            $this->logger->warning('Storage kit: the identifier columns of {table} get utf8mb4_bin, a PAD SPACE collation ("bob " = "bob"): the server is MySQL 5.7 or older than 8.0.17, or server_version "{version}" has no patch number. Set the full server_version (for example 8.0.36) on MySQL 8.', [
                'table'   => $metadata->getTableName(),
                'version' => $serverVersion ?? 'detected',
            ]);
        }

        $table = $args->getClassTable();
        foreach ($fields as $field) {
            /** @var non-empty-string $column mapped column names are never empty */
            $column = $metadata->getColumnName($field);
            // @phpstan-ignore function.alreadyNarrowedType (Table::edit() exists only from DBAL 4.5)
            $edited = self::collate($table, $column, $collation, method_exists(Table::class, 'edit'));
            if ($edited instanceof Table) {
                $table = $edited;
            }
        }
        if ($table !== $args->getClassTable()) {
            $args->setClassTable($table);
        }
    }

    /**
     * Gives `$column` the utf8mb4 charset and `$collation`: through a table editor (DBAL 4.5+; returns the new table) or
     * in place (older DBAL; returns null).
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

        $table->getColumn($column)
            ->setPlatformOption('charset', 'utf8mb4')
            ->setPlatformOption('collation', $collation);

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
