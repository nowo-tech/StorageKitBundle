<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\SequenceGenerator;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;
use Nowo\StorageKitBundle\Repository\StorageProfileRepository;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use ReflectionException;
use ReflectionProperty;
use Throwable;

use function array_chunk;
use function array_map;
use function bin2hex;
use function count;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_object;
use function is_scalar;
use function is_string;
use function random_bytes;
use function spl_object_id;
use function sprintf;

/**
 * The entity manager of the bundle entities, plus the DBAL connection and the (prefixed) table names for the bounded
 * raw-SQL statements. The host-called writes ({@see self::insert()}, {@see self::update()}, {@see self::updateRows()})
 * never flush any manager.
 *
 * Since 2.0 (REQ-ARCH-001 rule 6) {@see self::manager()} is always a manager of the bundle's own, never the
 * application's: transaction-doctrine's {@see PrivateEntityManager} on the connection, mapping and tables of the
 * default manager (1.8 opt-in, the only mode since 2.0), so no bundle flush ever writes the application's pending
 * changes; or a dedicated `doctrine.entity_manager` (1.5, the DDD option), fetched per call so a reset / closed
 * manager is replaced in worker mode. The private manager is dropped at every unit-of-work boundary and replaced when
 * closed; the application's managers are never flushed, cleared, closed or reset by it.
 */
final readonly class StorageEntityManager
{
    /** Identifiers per `UPDATE … IN (…)` statement of {@see self::updateRows()} (bound-parameter limits). */
    public const UPDATE_CHUNK = 1000;

    /** Default seconds {@see self::transactional()} waits for the owner lock on MySQL / MariaDB (`conflict_response.owner_lock_timeout`). */
    public const OWNER_LOCK_TIMEOUT = 5;

    /**
     * @param string|null $name `doctrine.entity_manager` (null: the default manager, the source of the private one)
     * @param int $ownerLockTimeout seconds to wait for the owner lock (`conflict_response.owner_lock_timeout`)
     * @param PrivateEntityManager|null $privateManager the private manager (null only with a dedicated `$name`)
     * @param object|null $hostFlushReporter @deprecated since 2.0, ignored (the 1.8 host-flush probe is gone with the
     *                                       host mode); removed in 3.0
     */
    public function __construct(
        private ManagerRegistry $registry,
        private ?string $name = null,
        private int $ownerLockTimeout = self::OWNER_LOCK_TIMEOUT,
        private ?PrivateEntityManager $privateManager = null,
        ?object $hostFlushReporter = null,
    ) {
        unset($hostFlushReporter); // deprecated since 2.0 and ignored (the host mode is gone); removed in 3.0
    }

    /**
     * The entity manager: the private one, or the dedicated `doctrine.entity_manager`.
     *
     * @throws LogicException when there is no private manager and `doctrine.entity_manager` does not name a dedicated
     *                        ORM entity manager (2.0 never writes through the application's default manager)
     */
    public function manager(): EntityManagerInterface
    {
        if ($this->privateManager instanceof PrivateEntityManager) {
            return $this->privateManager;
        }
        if (!$this->ownsManager()) {
            throw new LogicException('NowoStorageKitBundle 2.0 never reads or writes its entities through your default entity manager: build StorageEntityManager with the private entity manager, or with a dedicated doctrine.entity_manager.');
        }
        $manager = $this->registry->getManager($this->name);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('NowoStorageKitBundle needs a Doctrine ORM entity manager (doctrine.entity_manager).');
        }

        return $manager;
    }

    /**
     * Seconds a write waits for the owner lock (`conflict_response.owner_lock_timeout`; 0 = no wait).
     */
    public function ownerLockTimeout(): int
    {
        return $this->ownerLockTimeout;
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
     * The name of the sequence that generates the identifier of `$class` when the database takes ids from a sequence
     * (PostgreSQL with DBAL 3: the ORM maps `AUTO` to a sequence and the column has no default); null when the
     * identifier is an identity / autoincrement column, which a raw `INSERT` can leave out. For a host statement that
     * inserts rows of a bundle table without the entity manager (1.6.1). Read-only: no shared state is touched.
     *
     * @param class-string $class
     */
    public function sequenceName(string $class): ?string
    {
        $manager  = $this->manager();
        $metadata = $manager->getClassMetadata($class);
        if (!$metadata->idGenerator instanceof SequenceGenerator) {
            return null;
        }
        $platform = $manager->getConnection()->getDatabasePlatform();
        $name     = $metadata->sequenceGeneratorDefinition['sequenceName'] ?? null;

        return $platform instanceof PostgreSQLPlatform && is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Inserts one new bundle entity with DBAL, built from its class metadata (1.3, architecture bar rule 6): no entity
     * manager is flushed, so nothing the application left pending in it is written. Inside an open transaction the
     * insert runs in a savepoint (the generated id is read before the savepoint is released; a failed insert rolls
     * back only the savepoint and rethrows the original error; 2.2: when the savepoint is gone too, the connection is
     * closed, {@see self::rollBackSavepoint()}). The generated id is set on the entity, which stays
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
            $this->noteWrite();
        } catch (Throwable $e) {
            if ($savepoint !== null) {
                self::rollBackSavepoint($connection, $savepoint);
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
     * `$guard` (1.7, one identifier only): `[field, allowed values]`; the row is written (and aligned) only while its
     * field still holds one of them, see {@see self::updateIf()}.
     *
     * @param class-string $class bundle entity class
     * @param list<int|string> $ids identifiers
     * @param array<string, mixed> $values field name => value
     * @param array{0: string, 1: list<string>}|null $guard
     *
     * @throws LogicException when a field is unknown, an identifier or encrypted
     */
    public function updateRows(string $class, array $ids, array $values, ?array $guard = null): int
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
        $idsType     = in_array($idType, [Types::INTEGER, Types::BIGINT, Types::SMALLINT], true) ? ArrayParameterType::INTEGER : ArrayParameterType::STRING;
        $guardParams = [];
        $guardTypes  = [];
        if ($guard !== null) {
            if (count($ids) !== 1 || !$metadata->hasField($guard[0]) || $guard[1] === []) {
                throw new LogicException(sprintf('%s::$%s is not a mapped field to guard a write with, or more than one row was given.', $class, $guard[0]));
            }
            $sql .= ' AND ' . $quoteStrategy->getColumnName($guard[0], $metadata, $platform) . ' IN (?)';
            $guardParams = [$guard[1]];
            $guardTypes  = [ArrayParameterType::STRING];
        }
        $changed = $this->atomically(static function (Connection $connection) use ($sql, $ids, $parameters, $types, $idsType, $guardParams, $guardTypes): int {
            $changed = 0;
            foreach (array_chunk($ids, self::UPDATE_CHUNK) as $chunk) {
                $changed += (int) $connection->executeStatement($sql, [...$parameters, $chunk, ...$guardParams], [...$types, $idsType, ...$guardTypes]);
            }

            return $changed;
        }, 'update');
        if ($guard !== null && $changed === 0) {
            return 0;
        }
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
     * is rethrown, even when the rollback fails too; 2.2: the connection is then closed ({@see self::rollBackTo()},
     * {@see self::rollBackSavepoint()}), so a lost savepoint never leaves a broken nesting level behind.
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
            $level = $connection->getTransactionNestingLevel();
            $connection->beginTransaction();
            try {
                $result = $work($connection);
                $connection->commit();
            } catch (Throwable $e) {
                self::rollBackTo($connection, $level);

                throw $e;
            }

            return $result;
        }
        // Inside the host's transaction: a later rollback of it undoes this unit (1.8: the private manager then knows).
        $this->noteWrite();
        // A unique name: a nested unit never releases or reuses the savepoint of the unit around it.
        $savepoint = 'nowo_storage_kit_' . $label . '_' . bin2hex(random_bytes(6));
        $connection->createSavepoint($savepoint);
        try {
            $result = $work($connection);
            $connection->releaseSavepoint($savepoint);
        } catch (Throwable $e) {
            self::rollBackSavepoint($connection, $savepoint);

            throw $e;
        }

        return $result;
    }

    /**
     * Rolls the connection back to the nesting level `$level` the unit started at (2.2, REQ-RUNTIME-003 rule 3, the
     * lost-savepoint pattern). When that fails too — the database already rolled back more than the unit (a MySQL /
     * MariaDB deadlock ends the whole transaction) or the connection is gone — the connection is closed, so its next
     * use reconnects with a clean nesting level instead of failing every later request of a worker. The caller then
     * rethrows its original error.
     */
    public static function rollBackTo(Connection $connection, int $level): void
    {
        try {
            while ($connection->getTransactionNestingLevel() > $level) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            $connection->close();
        }
    }

    /**
     * Rolls back to the unit's own savepoint (2.2, same pattern as {@see self::rollBackTo()}): when the savepoint is gone
     * (a deadlock rolled back the whole transaction, "SAVEPOINT does not exist"), the connection is closed, never
     * treated as rolled back.
     */
    public static function rollBackSavepoint(Connection $connection, string $savepoint): void
    {
        try {
            $connection->rollbackSavepoint($savepoint);
        } catch (Throwable) {
            $connection->close();
        }
    }

    /**
     * Runs `$work` as one write unit (1.7, REQ-RUNTIME-003 rules 1–3) through transaction-doctrine's runner on the
     * bundle's own manager ({@see DoctrineTransactionRunner::forEntityManager()}): one transaction, the manager flushed
     * before the commit, the connection's nesting level repaired after a failure. 2.0 has no host-mode branch: the
     * manager is the private one (cleared after a storage failure; a closed private manager is replaced on its next
     * use, the application's managers are never touched) or the dedicated `doctrine.entity_manager` (reset through the
     * registry after a storage failure). The application's manager is never flushed.
     *
     * `$ownerLock` (an owner key, {@see StorageProfileRepository::ownerLock()}) serializes the writes of one storage
     * owner ({@see OwnerLock}: MySQL / MariaDB `GET_LOCK()` and, since 1.7.1, PostgreSQL `pg_advisory_xact_lock()` wait
     * up to `conflict_response.owner_lock_timeout` seconds). A lock not obtained in time throws
     * {@see TransientStorageFailure}: the bundle routes answer CONFLICT_001.
     *
     * A deadlock, lock wait timeout or serialization failure is rethrown as TransientStorageFailure (the storage error
     * as previous); anything else unchanged.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @throws TransientStorageFailure when the storage failed transiently or the owner lock was not obtained in time
     *
     * @return T
     */
    public function transactional(callable $work, ?string $ownerLock = null): mixed
    {
        $manager    = $this->manager();
        $connection = $manager->getConnection();
        $runner     = $this->privateManager instanceof PrivateEntityManager
            ? DoctrineTransactionRunner::forEntityManager($manager)
            : DoctrineTransactionRunner::forEntityManager($manager, $this->registry, $this->name);
        $timeout = $this->ownerLockTimeout;
        $named   = OwnerLock::acquire($connection, $ownerLock, $timeout);
        try {
            // @igor-ignore - The closure runs synchronously inside the runner; nothing is kept on the service.
            return $runner->run(static function () use ($connection, $ownerLock, $timeout, $work): mixed {
                OwnerLock::acquireInside($connection, $ownerLock, $timeout);

                return $work();
            });
        } catch (Throwable $e) {
            // A failed flush closes the manager: only a closed one is replaced.
            $this->resetIfClosed();

            throw $e;
        } finally {
            OwnerLock::release($connection, $named);
        }
    }

    /**
     * Runs `$work` as one unit ({@see self::atomically()}: a savepoint inside your transaction, else a transaction of
     * its own) under the owner lock of `$ownerLock` ({@see OwnerLock}), flushing no manager (1.7, rule 4): for the rows
     * the bundle creates on first use from host-called code (the instance's `storage` section row, a config profile's
     * row), so two first uploads at once create one row. Read the row again inside `$work` before creating it.
     *
     * Inside your open transaction on MySQL / MariaDB the session lock `GET_LOCK()` is released when `$work` returns,
     * before your transaction commits (PostgreSQL's transaction lock is held until then). Re-read with a locking read
     * there (1.7.1, audit UH-SK-2: {@see StorageProfileRepository::lockInstanceRow()}): it waits for a row another
     * request inserted and has not committed yet, instead of missing it.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @throws TransientStorageFailure when the owner lock is not obtained in time
     *
     * @return T
     */
    public function exclusively(callable $work, string $ownerLock): mixed
    {
        $connection = $this->connection();
        $timeout    = $this->ownerLockTimeout;
        $named      = OwnerLock::acquire($connection, $ownerLock, $timeout);
        try {
            // @igor-ignore - The closure runs synchronously inside the unit; nothing is kept on the service.
            return $this->atomically(static function (Connection $connection) use ($ownerLock, $timeout, $work): mixed {
                OwnerLock::acquireInside($connection, $ownerLock, $timeout);

                return $work();
            }, 'owner');
        } finally {
            OwnerLock::release($connection, $named);
        }
    }

    /**
     * Writes the current value of `$fields` of one bundle entity only while `$guardField` still holds one of
     * `$expected` in the database (1.7, rule 4: a compare-and-set, so a status another request changed meanwhile is
     * never overwritten). True when the row was written; the managed instance is then aligned like
     * {@see self::update()}. False when the guard no longer matched: nothing was written and the entity is refreshed
     * from the database (its unsaved changes are dropped). No manager is flushed.
     *
     * @param list<string> $fields mapped field names (never an encrypted one)
     * @param list<string> $expected values of `$guardField` the write is allowed from
     *
     * @throws LogicException when the entity has no identifier yet, or a field is unknown or encrypted
     */
    public function updateIf(object $entity, array $fields, string $guardField, array $expected): bool
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
        if ($this->updateRows($entity::class, [$id], $values, [$guardField, $expected]) === 1) {
            return true;
        }
        if ($manager->contains($entity)) {
            $manager->refresh($entity);
        }

        return false;
    }

    /**
     * Whether the bundle entities live on a manager of their own: the private manager, or `doctrine.entity_manager`
     * names one other than the host's default. Always true for the services the bundle registers (2.0).
     */
    public function ownsManager(): bool
    {
        return $this->privateManager instanceof PrivateEntityManager
            || ($this->name !== null && $this->name !== $this->registry->getDefaultManagerName());
    }

    /**
     * Whether the bundle reads and writes through its private entity manager (false only with a dedicated manager).
     */
    public function isPrivate(): bool
    {
        return $this->privateManager instanceof PrivateEntityManager;
    }

    /**
     * The bundle manager's own instance of a bundle entity (2.0, lesson W1-2): `$entity` itself when that manager holds
     * it or when it has no identifier yet (a new row the caller persists), otherwise the row loaded again by its
     * identifier. A bundle entity the application loaded through its own manager (a route value resolver, its
     * repository, a query) is therefore never taken for a new row by the private manager (no duplicate INSERT, no
     * "new entity found through a relationship"), and the application's instance is never attached, detached or
     * flushed by the bundle. Changes the application made to its own instance are not carried over: pass them through
     * the bundle's API.
     *
     * @template T of object
     *
     * @param T $entity a bundle entity
     *
     * @throws LogicException when the row no longer exists
     *
     * @return T
     */
    public function adopt(object $entity): object
    {
        $manager = $this->manager();
        if ($manager->contains($entity)) {
            return $entity;
        }
        $metadata = $manager->getClassMetadata($entity::class);
        $id       = $metadata->getIdentifierValues($entity);
        if ($id === []) {
            return $entity;
        }
        $class = $metadata->getName();
        /** @var T|null $own the class of `$entity` (its own mapping's) */
        $own = $manager->find($class, $id);
        if ($own === null) {
            throw new LogicException(sprintf('%s %s no longer exists.', $class, implode(', ', array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : get_debug_type($value), $id))));
        }

        return $own;
    }

    /**
     * Flushes `$manager` (default: {@see self::manager()}, the bundle's own manager), the only way the bundle flushes.
     */
    public function flush(?EntityManagerInterface $manager = null): void
    {
        ($manager ?? $this->manager())->flush();
    }

    /**
     * The private manager learns that a statement of the bundle bypassed its unit of work at the current transaction
     * level (1.8): a rollback of that level then makes its identity map stale, so it is replaced. No-op otherwise.
     */
    private function noteWrite(): void
    {
        $this->privateManager?->noteWrite();
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
        // A closed private manager is replaced on its next use; the registry (the application's managers) is not touched.
        if ($this->privateManager instanceof PrivateEntityManager) {
            return;
        }
        if (!$this->manager()->isOpen()) {
            $this->registry->resetManager($this->name);
        }
    }
}
