<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;
use ReflectionException;
use ReflectionProperty;
use Throwable;

use function array_chunk;
use function bin2hex;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_object;
use function is_string;
use function random_bytes;
use function spl_object_id;
use function sprintf;

/**
 * The entity manager of the bundle entities (`doctrine.entity_manager`, null = the default one), fetched per call so a
 * reset / closed manager is replaced in worker mode, plus the DBAL connection and the (prefixed) table names for the
 * bounded raw-SQL statements. Since 1.5 a dedicated manager is supported (the entities are mapped on it only); the
 * host-called writes ({@see self::insert()}, {@see self::update()}, {@see self::updateRows()}) never flush any manager.
 */
final readonly class StorageEntityManager
{
    /** Identifiers per `UPDATE … IN (…)` statement of {@see self::updateRows()} (bound-parameter limits). */
    public const UPDATE_CHUNK = 1000;

    /**
     * @param string|null $name entity manager name
     */
    public function __construct(
        private ManagerRegistry $registry,
        private ?string $name = null,
    ) {
    }

    /**
     * The entity manager.
     *
     * @throws LogicException when the configured manager is not an ORM entity manager
     */
    public function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManager($this->name);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('NowoStorageKitBundle needs a Doctrine ORM entity manager (doctrine.entity_manager).');
        }

        return $manager;
    }

    /**
     * Its DBAL connection.
     */
    public function connection(): Connection
    {
        return $this->manager()->getConnection();
    }

    /**
     * The table name of a bundle entity, prefix included.
     *
     * @param class-string $class
     */
    public function table(string $class): string
    {
        return $this->manager()->getClassMetadata($class)->getTableName();
    }

    /**
     * Inserts one new bundle entity with DBAL, built from its class metadata (1.3, architecture bar rule 6): no entity
     * manager is flushed, so nothing the application left pending in it is written. Inside an open transaction the
     * insert runs in a savepoint (the generated id is read before the savepoint is released; a failed insert rolls
     * back only the savepoint and rethrows the original error, even when the savepoint rollback fails too). The generated id is set on the entity, which stays
     * unmanaged.
     *
     * @return int|string the generated identifier
     */
    public function insert(object $entity): int|string
    {
        $manager       = $this->manager();
        $connection    = $manager->getConnection();
        $platform      = $connection->getDatabasePlatform();
        $quoteStrategy = $manager->getConfiguration()->getQuoteStrategy();
        $metadata      = $manager->getClassMetadata($entity::class);
        $data          = [];
        $types         = [];
        foreach ($metadata->getFieldNames() as $field) {
            if ($metadata->isIdentifier($field) && $metadata->idGenerator->isPostInsertGenerator()) {
                continue;
            }
            $column         = $quoteStrategy->getColumnName($field, $metadata, $platform);
            $data[$column]  = $metadata->isIdentifier($field) ? $this->generated($metadata->idGenerator->generateId($manager, $entity), $field) : $metadata->getFieldValue($entity, $field);
            $types[$column] = (string) $metadata->getTypeOfField($field);
        }
        foreach ($metadata->getAssociationMappings() as $name => $association) {
            $target         = $metadata->getFieldValue($entity, $name);
            $targetMetadata = $manager->getClassMetadata($association->targetEntity);
            foreach ($association instanceof ToOneOwningSideMapping ? $association->joinColumns : [] as $joinColumn) {
                $column         = $quoteStrategy->getJoinColumnName($joinColumn, $metadata, $platform);
                $referenced     = $targetMetadata->getFieldForColumn($joinColumn->referencedColumnName);
                $data[$column]  = is_object($target) ? $targetMetadata->getFieldValue($target, $referenced) : null;
                $types[$column] = (string) $targetMetadata->getTypeOfField($referenced);
            }
        }
        $table     = $quoteStrategy->getTableName($metadata, $platform);
        $savepoint = $connection->isTransactionActive() ? 'nowo_storage_kit_insert' : null;
        if ($savepoint !== null) {
            $connection->createSavepoint($savepoint);
        }
        try {
            $connection->insert($table, $data, $types);
            $idField = $metadata->getSingleIdentifierFieldName();
            $id      = $metadata->idGenerator->isPostInsertGenerator() ? $connection->lastInsertId() : $data[$quoteStrategy->getColumnName($idField, $metadata, $platform)];
            if ($savepoint !== null) {
                $connection->releaseSavepoint($savepoint);
            }
        } catch (Throwable $e) {
            if ($savepoint !== null) {
                try {
                    $connection->rollbackSavepoint($savepoint);
                } catch (Throwable) {
                    // The savepoint is gone too (the database rolled back more than the statement): the original
                    // error explains what happened, so it is the one rethrown.
                }
            }

            throw $e;
        }
        $id = is_numeric($id) && (string) (int) $id === (string) $id ? (int) $id : $id;
        // @igor-ignore - Sets the generated id on the inserted entity (the argument), not on the shared metadata.
        $metadata->setIdentifierValues($entity, [$idField => $id]);

        return $id;
    }

    /**
     * Writes the current value of `$fields` of one bundle entity with a DBAL `UPDATE` by its identifier (1.5,
     * architecture bar rule 6): no entity manager is flushed, so nothing the application left pending is written.
     * When the entity is managed, its original data is aligned with the written values, so a later flush of that
     * manager does not write them again. Inside an open transaction the statement is part of it.
     *
     * @param list<string> $fields mapped field names (never an encrypted one: see {@see self::updateRows()})
     *
     * @throws LogicException when the entity has no identifier yet, or a field is unknown or encrypted
     */
    public function update(object $entity, array $fields): void
    {
        $manager  = $this->manager();
        $metadata = $manager->getClassMetadata($entity::class);
        $id       = $metadata->getIdentifierValues($entity)[$metadata->getSingleIdentifierFieldName()] ?? null;
        if (!is_int($id) && !is_string($id)) {
            throw new LogicException(sprintf('%s has no identifier yet: insert it first.', $entity::class));
        }
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $metadata->getFieldValue($entity, $field);
        }
        $this->updateRows($entity::class, [$id], $values);
    }

    /**
     * Sets `$values` (field => value) on the rows of `$ids` with one DBAL `UPDATE` (1.5, rule 6: no flush), then
     * applies the same values to the instances of those rows the entity manager already manages, original data
     * included, so the identity map shows what the database holds and no later flush writes them again. Returns the
     * number of rows the database reports as changed.
     *
     * Encrypted fields (`#[Encrypted]`) are refused: a DBAL statement would bypass the encryption listener and store
     * the plain value. The check reads the property where the mapping declares it (a mapped superclass included) and
     * fails closed when it cannot be read.
     *
     * The ids are written {@see self::UPDATE_CHUNK} per statement, all chunks in one unit ({@see self::atomically()}):
     * inside the host's transaction a savepoint, so a failing statement rolls back only the bundle's change and the
     * host's transaction stays usable (PostgreSQL aborts a whole transaction on a failed statement); otherwise a
     * transaction of its own.
     *
     * @param class-string $class bundle entity class
     * @param list<int|string> $ids identifiers
     * @param array<string, mixed> $values field name => value
     *
     * @throws LogicException when a field is unknown, an identifier or encrypted
     */
    public function updateRows(string $class, array $ids, array $values): int
    {
        if ($ids === [] || $values === []) {
            return 0;
        }
        $manager       = $this->manager();
        $connection    = $manager->getConnection();
        $platform      = $connection->getDatabasePlatform();
        $quoteStrategy = $manager->getConfiguration()->getQuoteStrategy();
        $metadata      = $manager->getClassMetadata($class);
        $set           = [];
        $parameters    = [];
        $types         = [];
        foreach ($values as $field => $value) {
            if (!$metadata->hasField($field) || $metadata->isIdentifier($field)) {
                throw new LogicException(sprintf('%s::$%s is not a writable mapped field.', $class, $field));
            }
            if ($this->isEncrypted($metadata->fieldMappings[$field]->declared ?? $metadata->getName(), $field)) {
                throw new LogicException(sprintf('%s::$%s is encrypted: it is never written with a DBAL statement.', $class, $field));
            }
            $set[]        = $quoteStrategy->getColumnName($field, $metadata, $platform) . ' = ?';
            $parameters[] = $value;
            $types[]      = (string) $metadata->getTypeOfField($field);
        }
        $idField = $metadata->getSingleIdentifierFieldName();
        $idType  = (string) $metadata->getTypeOfField($idField);
        $sql     = 'UPDATE ' . $quoteStrategy->getTableName($metadata, $platform) . ' SET ' . implode(', ', $set)
            . ' WHERE ' . $quoteStrategy->getColumnName($idField, $metadata, $platform) . ' IN (?)';
        $idsType = in_array($idType, [Types::INTEGER, Types::BIGINT, Types::SMALLINT], true) ? ArrayParameterType::INTEGER : ArrayParameterType::STRING;
        $changed = $this->atomically(static function (Connection $connection) use ($sql, $ids, $parameters, $types, $idsType): int {
            $changed = 0;
            foreach (array_chunk($ids, self::UPDATE_CHUNK) as $chunk) {
                $changed += (int) $connection->executeStatement($sql, [...$parameters, $chunk], [...$types, $idsType]);
            }

            return $changed;
        }, 'update');
        $unitOfWork = $manager->getUnitOfWork();
        foreach ($ids as $id) {
            $managed = $unitOfWork->tryGetById($id, $metadata->rootEntityName);
            if (!$managed instanceof $class) {
                continue;
            }
            foreach ($values as $field => $value) {
                // @igor-ignore - Aligns the managed instance (an argument row of this call) with the row just written.
                $metadata->setFieldValue($managed, $field, $value);
                // @igor-ignore - Aligns the original data of that managed row (per call), so no later flush rewrites it.
                $unitOfWork->setOriginalEntityProperty(spl_object_id($managed), $field, $value);
            }
        }

        return $changed;
    }

    /**
     * Runs `$work` with the bundle connection as one unit (1.5, audit NH-1 / NH-2): inside an open transaction (the
     * host's) in a savepoint of its own, released on success and rolled back on failure, so the host's transaction
     * stays usable and its own work can still be committed; otherwise in a transaction of its own. The original error
     * is rethrown, even when the rollback fails too.
     *
     * @template T
     *
     * @param callable(Connection): T $work
     * @param string $label savepoint label (letters and underscores)
     *
     * @return T
     */
    public function atomically(callable $work, string $label = 'unit'): mixed
    {
        $connection = $this->connection();
        if (!$connection->isTransactionActive()) {
            return $connection->transactional(static fn (Connection $connection): mixed => $work($connection));
        }
        // A unique name: a nested unit never releases or reuses the savepoint of the unit around it.
        $savepoint = 'nowo_storage_kit_' . $label . '_' . bin2hex(random_bytes(6));
        $connection->createSavepoint($savepoint);
        try {
            $result = $work($connection);
            $connection->releaseSavepoint($savepoint);
        } catch (Throwable $e) {
            try {
                $connection->rollbackSavepoint($savepoint);
            } catch (Throwable) {
                // The savepoint is gone too: the original error explains what happened, so it is the one rethrown.
            }

            throw $e;
        }

        return $result;
    }

    /**
     * Whether `$class::$field` (where the mapping declares it) carries `#[Encrypted]`; true when it cannot be read
     * (fail closed: never write an unknown property with a DBAL statement).
     *
     * @param class-string $class declaring class
     */
    private function isEncrypted(string $class, string $field): bool
    {
        try {
            return (new ReflectionProperty($class, $field))->getAttributes(Encrypted::class) !== [];
        } catch (ReflectionException) {
            return true;
        }
    }

    /**
     * The value of `$field` from a generator result (the assigned generator returns the identifier map).
     */
    private function generated(mixed $value, string $field): mixed
    {
        return is_array($value) ? ($value[$field] ?? null) : $value;
    }

    /**
     * Replaces the entity manager when a failed transaction closed it (Doctrine closes it on any exception inside
     * wrapInTransaction()), so the rest of the request — and the next one in a worker — keeps a usable manager.
     */
    public function resetIfClosed(): void
    {
        if (!$this->manager()->isOpen()) {
            $this->registry->resetManager($this->name);
        }
    }
}
