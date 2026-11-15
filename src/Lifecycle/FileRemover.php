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
 * ({@see StorageEntityManager::atomically()}: a savepoint inside a host transaction). The `file_removed` audit event is
 * recorded only outside a transaction (after commit, rule 7); the loaded row leaves the bundle's unit of work.
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
     * Deletes the objects, then the references and the row (or leaves the file to the purge when the bucket refuses).
     */
    public function remove(string $uuid, string $contextType, string $contextId): bool
    {
        $file = $this->find($uuid, $contextType, $contextId);
        if (!$file instanceof StoredFile) {
            return false;
        }
        $id    = (int) $file->getId();
        $scope = $file->getProfile()->getScope();
        if (!$this->deleteObjects($file)) {
            $this->expireRow($id);

            return true;
        }
        $files      = $this->entityManager->table(StoredFile::class);
        $references = $this->entityManager->table(StoredFileReference::class);
        // @igor-ignore - The closure runs synchronously inside atomically(); nothing is kept on the service.
        $this->entityManager->atomically(static function (Connection $connection) use ($files, $references, $id): void {
            $connection->executeStatement('DELETE FROM ' . $references . ' WHERE file_id = ?', [$id], [Types::INTEGER]);
            $connection->executeStatement('DELETE FROM ' . $files . ' WHERE id = ?', [$id], [Types::INTEGER]);
        }, 'remove');
        if (!$this->entityManager->connection()->isTransactionActive()) {
            $this->audit->record(StorageAuditEvent::FILE_REMOVED, $scope, null, ['file' => $file->getUuid(), 'context' => $contextType . '/' . $contextId]);
        }

        return true;
    }

    /**
     * Drops the references and expires the orphan window (the next purge run deletes the file).
     */
    public function expire(string $uuid, string $contextType, string $contextId): bool
    {
        $file = $this->find($uuid, $contextType, $contextId);
        if (!$file instanceof StoredFile) {
            return false;
        }
        $this->expireRow((int) $file->getId());

        return true;
    }

    /**
     * The file with that public id stored in exactly that context, detached from the bundle's unit of work.
     */
    private function find(string $uuid, string $contextType, string $contextId): ?StoredFile
    {
        $uuid = strtolower($uuid);
        if (preg_match(self::UUID, $uuid) !== 1 || !FileContexts::isType($contextType) || !FileContexts::isId($contextId)) {
            return null;
        }
        $file = $this->files->findOneByUuid($uuid);
        if (!$file instanceof StoredFile) {
            return null;
        }
        $this->entityManager->manager()->detach($file);

        return ExactIdentifier::same($file->getContextType(), $contextType) && ExactIdentifier::same($file->getContextId(), $contextId) ? $file : null;
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

    private function deleteObjects(StoredFile $file): bool
    {
        $settings = $this->profiles->settingsOf($file->getProfile());
        if (!$settings instanceof S3Settings) {
            $this->logger->warning('Could not remove the objects of stored file {uuid}: its profile is not usable; left to the purge.', ['uuid' => $file->getUuid()]);

            return false;
        }
        try {
            $connection = $this->storage->connection($settings);
            foreach ($file->objectKeys() as $key) {
                $connection->delete($key);
            }

            return true;
        } catch (Throwable) {
            $this->logger->warning('Could not remove the objects of stored file {uuid}; left to the purge.', ['uuid' => $file->getUuid()]);

            return false;
        }
    }
}
