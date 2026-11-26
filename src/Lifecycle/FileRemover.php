<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Lifecycle;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Nowo\StorageKitBundle\Audit\AuditRecorder;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Entity\StoredFileReference;
use Nowo\StorageKitBundle\Identifier\ExactIdentifier;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function preg_match;
use function strtolower;

/**
 * Default {@see FileRemoverInterface} (1.6). The row is looked up by public id, then its context is compared with `===`
 * (no SQL collation decides it); the statements go by primary key, each pair in one unit
 * ({@see StorageEntityManager::atomically()}: a savepoint inside a host transaction). 2.2: `remove()` locks and expires
 * the row and reads its object keys under that lock before any bucket call ({@see FileClaims}), so a variant written
 * concurrently is never left behind. The `file_removed` /
 * `file_expired` audit event is recorded at once outside a transaction, else by {@see self::afterCommit()} once the host
 * committed (rule 7); the loaded row leaves the bundle's unit of work.
 *
 * Stateless (REQ-RUNTIME-002).
 */
final readonly class FileRemover implements FileRemoverInterface
{
    /** `orphaned_since` of an expired file: long before any retention window. */
    public const EXPIRED_SINCE = '2000-01-01 00:00:00';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * Dependencies.
     */
    public function __construct(
        private StorageEntityManager $entityManager,
        private StoredFileRepository $files,
        private StorageProfiles $profiles,
        private FileStorage $storage,
        private AuditRecorder $audit,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Deletes the objects, then the references and the row (or expires the file for the purge when the bucket refuses).
     */
    public function remove(string $uuid, string $contextType, string $contextId): ?RemovedFile
    {
        $file = $this->find($uuid, $contextType, $contextId);
        if (!$file instanceof StoredFile) {
            return null;
        }
        $id = (int) $file->getId();
        // 2.2 (audit ZCK-SK-M1): the row is locked and expired first, and its object keys are read under that lock, so
        // a variant written meanwhile is either in the list or refused; then the objects go with no lock held.
        $keys = FileClaims::claim($this->entityManager, $id);
        if ($keys === null) {
            return null;
        }
        if (!$this->deleteObjects($file, $keys)) {
            return $this->done($file, true);
        }
        FileClaims::delete($this->entityManager, $id);

        return $this->done($file, false);
    }

    /**
     * Drops the references and expires the orphan window (not served any more; the next purge run deletes the file).
     */
    public function expire(string $uuid, string $contextType, string $contextId): ?RemovedFile
    {
        $file = $this->find($uuid, $contextType, $contextId);
        if (!$file instanceof StoredFile) {
            return null;
        }
        $this->expireRow((int) $file->getId());

        return $this->done($file, true);
    }

    /**
     * Records `file_removed` / `file_expired` for each removal.
     */
    public function afterCommit(RemovedFile ...$removed): void
    {
        foreach ($removed as $file) {
            $this->audit->record($file->expired ? StorageAuditEvent::FILE_EXPIRED : StorageAuditEvent::FILE_REMOVED, $file->scope, null, [
                'file'    => $file->uuid,
                'context' => $file->contextType . '/' . $file->contextId,
            ]);
        }
    }

    /**
     * The removal; its audit event is recorded now unless a transaction is open (then after the commit, rule 7).
     */
    private function done(StoredFile $file, bool $expired): RemovedFile
    {
        $removed = new RemovedFile($file->getUuid(), $file->getContextType(), $file->getContextId(), $file->getProfile()->getScope(), $expired);
        if (!$this->entityManager->connection()->isTransactionActive()) {
            $this->afterCommit($removed);
        }

        return $removed;
    }

    /**
     * The file with that public id stored in exactly that context, detached from the bundle's unit of work. The context
     * is compared on a DBAL read first, so a file of another context is never loaded into (or detached from) the
     * entity manager (audit QJ-I2).
     */
    private function find(string $uuid, string $contextType, string $contextId): ?StoredFile
    {
        $uuid = strtolower($uuid);
        if (preg_match(self::UUID, $uuid) !== 1 || !FileContexts::isType($contextType) || !FileContexts::isId($contextId)) {
            return null;
        }
        $row = $this->entityManager->connection()->fetchAssociative(
            'SELECT context_type, context_id FROM ' . $this->entityManager->table(StoredFile::class) . ' WHERE uuid = ?',
            [$uuid],
        );
        if ($row === false || !ExactIdentifier::same($row['context_type'], $contextType) || !ExactIdentifier::same($row['context_id'], $contextId)) {
            return null;
        }
        $file = $this->files->findOneByUuid($uuid);
        if ($file instanceof StoredFile) {
            $this->entityManager->manager()->detach($file);
        }

        return $file instanceof StoredFile && ExactIdentifier::same($file->getContextType(), $contextType) && ExactIdentifier::same($file->getContextId(), $contextId) ? $file : null;
    }

    private function expireRow(int $id): void
    {
        $files      = $this->entityManager->table(StoredFile::class);
        $references = $this->entityManager->table(StoredFileReference::class);
        // @igor-ignore - The closure runs synchronously inside atomically(); nothing is kept on the service.
        $this->entityManager->atomically(static function (Connection $connection) use ($files, $references, $id): void {
            $connection->executeStatement('DELETE FROM ' . $references . ' WHERE file_id = ?', [$id], [Types::INTEGER]);
            $connection->executeStatement(
                'UPDATE ' . $files . ' SET orphaned_since = ? WHERE id = ?',
                [new DateTimeImmutable(self::EXPIRED_SINCE), $id],
                [Types::DATETIME_IMMUTABLE, Types::INTEGER],
            );
        }, 'expire');
    }

    /**
     * @param list<string> $keys
     */
    private function deleteObjects(StoredFile $file, array $keys): bool
    {
        $settings = $this->profiles->settingsOf($file->getProfile());
        if (!$settings instanceof S3Settings) {
            $this->logger->warning('Could not remove the objects of stored file {uuid}: its profile is not usable; left to the purge.', ['uuid' => $file->getUuid()]);

            return false;
        }
        try {
            $connection = $this->storage->connection($settings);
            foreach ($keys as $key) {
                $connection->delete($key);
            }

            return true;
        } catch (Throwable) {
            $this->logger->warning('Could not remove the objects of stored file {uuid}; left to the purge.', ['uuid' => $file->getUuid()]);

            return false;
        }
    }
}
