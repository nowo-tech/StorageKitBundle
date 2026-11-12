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
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\PersonalData\UploaderIdentity;

use function method_exists;

/**
 * Exact-match collation for the uploader identity columns of `storage_kit_file` on MySQL / MariaDB (1.5.2, house rule
 * LQ): `uploaded_by` and `uploaded_by_key`.
 *
 * The host default collations fold case and accents (`utf8mb4_0900_ai_ci`, `utf8mb4_unicode_ci`, MariaDB
 * `utf8mb4_uca1400_ai_ci`) and most are PAD SPACE (trailing spaces ignored), so `alice` = `Alice` = `álice` = `alice `.
 * The repository already re-checks every row with `===` ({@see UploaderIdentity});
 * the binary NO PAD collation keeps the database itself exact: MariaDB `utf8mb4_nopad_bin`, MySQL 8.0+
 * `utf8mb4_0900_bin`, MySQL 5.7 `utf8mb4_bin` (PAD SPACE, the only binary one it has). PostgreSQL and SQLite compare
 * exactly and are untouched (they reject MySQL collation names, so it cannot live in the mapping). Applied to the
 * generated schema (schema tool, `doctrine:migrations:diff`); existing tables need the `ALTER TABLE` of UPGRADING.md.
 *
 * Stateless: safe in FrankenPHP worker mode.
 */
final readonly class UploaderCollationListener
{
    /** @var list<string> the mapped fields that get the collation */
    public const FIELDS = ['uploadedBy', 'uploadedByKey'];

    /**
     * The bundle's entity manager (its connection decides the platform).
     */
    public function __construct(
        private StorageEntityManager $entityManager,
    ) {
    }

    /**
     * The exact-match collation on that platform, null when its default is already exact.
     *
     * @return non-empty-string|null
     */
    public static function collationFor(AbstractPlatform $platform): ?string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform       => 'utf8mb4_nopad_bin',
            $platform instanceof MySQL80Platform       => 'utf8mb4_0900_bin',
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
        if ($metadata->getName() !== StoredFile::class) {
            return;
        }
        $collation = self::collationFor($this->entityManager->manager()->getConnection()->getDatabasePlatform());
        if ($collation === null) {
            return;
        }

        $table = $args->getClassTable();
        foreach (self::FIELDS as $field) {
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
}
