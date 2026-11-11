<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Throwable;

use function is_array;
use function is_numeric;
use function is_object;

/**
 * The entity manager of the bundle entities (`doctrine.entity_manager`, null = the default one), fetched per call so a
 * reset / closed manager is replaced in worker mode, plus the DBAL connection and the (prefixed) table names for the
 * bounded raw-SQL statements.
 */
final readonly class StorageEntityManager
{
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
