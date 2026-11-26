<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Nowo\StorageKitBundle\Audit\AuditRecorder;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Concurrency\StorageConflicts;
use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Context\FileContextTarget;
use Nowo\StorageKitBundle\Context\GenericFileContextInterface;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Download\MediaTypes;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Identifier\ExactIdentifier;
use Nowo\StorageKitBundle\PersonalData\UploaderIdentity;
use Nowo\StorageKitBundle\PersonalData\UploaderKeyResolverInterface;
use Nowo\StorageKitBundle\PersonalData\UserIdUploaderKeyResolver;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Nowo\StorageKitBundle\Storage\ErrorScrubber;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\StorageKitBundle\Storage\StorageConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

use function bin2hex;
use function fclose;
use function fread;
use function gmdate;
use function hash_final;
use function hash_init;
use function hash_update_stream;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function min;
use function random_bytes;
use function rewind;
use function strlen;

/**
 * Generic files (2.2): {@see FileUploaderInterface} (bytes stored as they are) and {@see FileVariantWriterInterface}
 * (a generated image attached to a stored file). See the interfaces for the contract.
 *
 * Upload order, so a refusal stores nothing: the context (it must be a {@see GenericFileContextInterface} the user may
 * write in), the declared type (exact match in the context's allow-list), the size (the context's limit and the
 * bundle's ceiling), the bucket, the owner's limit, then the source file (opened once: magic bytes of a declared image
 * type, SHA-256 and the transfer read the same handle), the object (a random server-generated `yyyy/mm/<uuid>` key: no
 * user input, no path traversal) and last the row (DBAL insert, a savepoint inside an open transaction). No lock is
 * taken while the bytes travel. A failed insert deletes the object again; a lock conflict is rethrown (CONFLICT_001).
 *
 * Stateless (REQ-RUNTIME-002): every handle is closed in the call that opened it.
 */
final readonly class FileUploader implements FileUploaderInterface, FileVariantWriterInterface
{
    /** Default `upload.files.max_bytes`: the ceiling of a generic file (a context's own limit can only be lower). */
    public const MAX_BYTES = 100 * 1024 * 1024;

    /** Variants {@see self::putVariant()} writes. */
    public const VARIANTS = [StoredFile::VARIANT_THUMB, StoredFile::VARIANT_PREVIEW];

    /**
     * Contexts, profiles, storage, image checks (variants), files, entity manager, audit, optional limit, logger, the
     * stable uploader key and the ceiling.
     */
    public function __construct(
        private FileContexts $contexts,
        private StorageProfiles $profiles,
        private FileStorage $storage,
        private ImageProcessor $images,
        private StoredFileRepository $files,
        private StorageEntityManager $entityManager,
        private AuditRecorder $audit,
        private ?UploadLimitInterface $limit = null,
        private LoggerInterface $logger = new NullLogger(),
        private UploaderKeyResolverInterface $uploaderKeys = new UserIdUploaderKeyResolver(),
        private int $maxBytes = self::MAX_BYTES,
    ) {
    }

    /**
     * Stores a generic file and returns its view; the audit event follows the commit (see the interface).
     */
    public function uploadFile(object $user, string $contextType, string $contextId, StoredFileSource $source, string $originalName, string $mediaType): StoredFileView
    {
        $context = FileContexts::isType($contextType) && FileContexts::isId($contextId) ? $this->contexts->get($contextType) : null;
        $target  = $context instanceof GenericFileContextInterface ? $context->writableTarget($user, $contextId) : null;
        if (!$context instanceof GenericFileContextInterface || !$target instanceof FileContextTarget) {
            throw new UploadRefused(UploadRefused::CONTEXT);
        }
        if (!MediaTypes::isValid($mediaType) || !in_array($mediaType, $context->acceptedTypes(), true)) {
            throw new UploadRefused(UploadRefused::TYPE);
        }
        if ($source->size === 0) {
            throw new UploadRefused(UploadRefused::EMPTY);
        }
        if ($source->size > min($context->maxBytes(), $this->maxBytes)) {
            throw new UploadRefused(UploadRefused::SIZE);
        }
        $profile  = $this->profiles->forTarget($target) ?? throw new UploadRefused(UploadRefused::OFF);
        $settings = $this->profiles->settingsOf($profile) ?? throw new UploadRefused(UploadRefused::OFF);
        if ($this->limit instanceof UploadLimitInterface && !$this->limit->allows($target, $source->size)) {
            throw new UploadRefused(UploadRefused::LIMIT);
        }

        $handle = $source->open();
        try {
            // A declared raster image must be one: its variants and previews are made from these bytes.
            if (MediaTypes::isRasterImage($mediaType) && ImageProcessor::sniff((string) fread($handle, 16)) !== $mediaType) {
                throw new UploadRefused(UploadRefused::TYPE);
            }
            $sha256 = $this->sha256($handle, $source->size);
            $uuid   = Uuid::v4()->toRfc4122();
            $key    = gmdate('Y/m') . '/' . $uuid;
            $file   = new StoredFile($profile, $contextType, $contextId, $key, FileNames::clean($originalName), $mediaType, $source->size, 0, 0, $sha256, $uuid);
            $this->store($handle, $settings, $file, $user, $target);
        } finally {
            fclose($handle);
        }
        $view = StoredFileView::fromEntity($file);
        if (!$this->entityManager->connection()->isTransactionActive()) {
            $this->afterCommit($view);
        }

        return $view;
    }

    /**
     * Records the `file_uploaded` audit event of each upload (after the host commit).
     */
    public function afterCommit(StoredFileView ...$uploaded): void
    {
        foreach ($uploaded as $view) {
            $this->audit->record(StorageAuditEvent::FILE_UPLOADED, $view->scope, $view->uploadedBy, [
                'file'    => $view->uuid,
                'context' => $view->contextType . '/' . $view->contextId,
                'bytes'   => $view->totalBytes,
            ]);
        }
    }

    /**
     * Attaches a generated image variant (see the interface).
     *
     * @throws InvalidArgumentException when `$variant` is not `thumb` or `preview`
     */
    public function putVariant(string $uuid, string $contextType, string $contextId, string $variant, string $bytes, string $mediaType): StoredFileView
    {
        if (!in_array($variant, self::VARIANTS, true)) {
            throw new InvalidArgumentException('Only the "thumb" and "preview" variants can be written.');
        }
        if ($bytes === '') {
            throw new UploadRefused(UploadRefused::EMPTY);
        }
        if (strlen($bytes) > $this->images->maxBytes()) {
            throw new UploadRefused(UploadRefused::SIZE);
        }
        if (!MediaTypes::isRasterImage($mediaType)) {
            throw new UploadRefused(UploadRefused::TYPE);
        }
        [$sniffed, $width, $height] = $this->images->header($bytes);
        if ($sniffed !== $mediaType) {
            throw new UploadRefused(UploadRefused::TYPE);
        }
        $file     = $this->files->findInContext($uuid, $contextType, $contextId) ?? throw new UploadRefused(UploadRefused::CONTEXT);
        $settings = $this->profiles->settingsOf($file->getProfile()) ?? throw new UploadRefused(UploadRefused::OFF);
        // The row is written with DBAL below: the loaded instance leaves the bundle's unit of work (never stale in it).
        $this->entityManager->manager()->detach($file);
        $connection = $this->storage->connection($settings);
        $key        = $file->getStorageKey() . '-' . $variant . '-' . bin2hex(random_bytes(4));
        $entry      = ['key' => $key, 'size' => strlen($bytes), 'width' => $width, 'height' => $height, 'mime' => $mediaType];
        try {
            $connection->write($key, $bytes, $mediaType);
        } catch (Throwable $e) {
            $this->discard($connection, $key);
            $this->logger->warning('Variant upload failed: {error}', ['error' => ErrorScrubber::message($e, $settings)]);

            throw new UploadRefused(UploadRefused::STORAGE);
        }
        try {
            [$replaced, $variants, $total] = $this->attach((int) $file->getId(), $contextType, $contextId, $variant, $entry);
        } catch (Throwable $e) {
            // Nothing points at the new object: delete it, then let the failure through (a lock conflict stays a
            // retry-safe conflict; `context` says the file went away meanwhile).
            $this->discard($connection, $key);

            throw $e;
        }
        // After the commit: the object the variant replaced (never the original's).
        if ($replaced !== null && $replaced !== $key && $replaced !== $file->getStorageKey()) {
            $this->discard($connection, $replaced);
        }

        return $this->view($file, $variants, $total);
    }

    /**
     * Writes the object from the open source, then inserts the row; a failure deletes the object again.
     *
     * @param resource $handle
     *
     * @throws UploadRefused `storage` on a bucket or database failure that is not a conflict
     */
    private function store($handle, S3Settings $settings, StoredFile $file, object $user, FileContextTarget $target): void
    {
        $connection = $this->storage->connection($settings);
        $key        = $file->getStorageKey();
        try {
            rewind($handle);
            // The bucket keeps the served type: an active type (HTML, SVG…) is never stored as itself.
            $connection->writeStream($key, $handle, MediaTypes::served($file->getMimeType()));
            // House rule LQ (1.5.2): a padded, control-character or over-long identifier is not stored (never trimmed or cut).
            $file->setUploadedBy($user instanceof UserInterface ? UploaderIdentity::storable($user->getUserIdentifier()) : null);
            $stableKey = $this->uploaderKeys->keyOf($user);
            $file->setUploadedByKey($stableKey === null || strlen($stableKey) > UploaderKeyResolverInterface::MAX_LENGTH ? null : UploaderIdentity::storable($stableKey));
            $file->setOwner($target->owner);
            $file->markOrphaned(new DateTimeImmutable());
            $this->entityManager->insert($file);
        } catch (Throwable $e) {
            $this->discard($connection, $key);
            if (StorageConflicts::isConflict($e)) {
                // REQ-RUNTIME-003 rule 8: a deadlock / lock wait on the insert is rethrown, never swallowed as a bucket
                // failure: the object is gone again, so the caller answers CONFLICT_001 (retry).
                throw $e;
            }
            $this->logger->warning('File upload failed: {error}', ['error' => ErrorScrubber::message($e, $settings)]);

            throw new UploadRefused(UploadRefused::STORAGE);
        }
    }

    /**
     * One short unit: lock the row, check it is still the same live file, write the variant list and the byte total.
     *
     * @param array{key: string, size: int, width: int, height: int, mime: string} $entry
     *
     * @return array{0: string|null, 1: array<string, array{key: string, size: int, width: int, height: int, mime: string}>, 2: int} replaced object key, variants, total bytes
     */
    private function attach(int $id, string $contextType, string $contextId, string $variant, array $entry): array
    {
        $table    = $this->entityManager->table(StoredFile::class);
        $platform = $this->entityManager->connection()->getDatabasePlatform();
        $lock     = $platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform ? ' FOR UPDATE' : '';

        // @igor-ignore - The closure runs synchronously inside atomically(); nothing is kept on the service.
        return $this->entityManager->atomically(static function (Connection $connection) use ($table, $lock, $id, $contextType, $contextId, $variant, $entry): array {
            $row = $connection->fetchAssociative(
                'SELECT context_type, context_id, size, variants FROM ' . $table . ' WHERE id = ? AND (orphaned_since IS NULL OR orphaned_since >= ?)' . $lock,
                [$id, new DateTimeImmutable(StoredFile::EXPIRED_BEFORE)],
                [Types::INTEGER, Types::DATETIME_IMMUTABLE],
            );
            if ($row === false || !ExactIdentifier::same($row['context_type'], $contextType) || !ExactIdentifier::same($row['context_id'], $contextId)) {
                throw new UploadRefused(UploadRefused::CONTEXT);
            }
            $variants           = self::variants($row['variants']);
            $replaced           = $variants[$variant]['key'] ?? null;
            $variants[$variant] = $entry;
            $total              = (int) $row['size'];
            foreach ($variants as $stored) {
                $total += $stored['size'];
            }
            $connection->executeStatement(
                'UPDATE ' . $table . ' SET variants = ?, total_bytes = ? WHERE id = ?',
                [$variants, $total, $id],
                [Types::JSON, Types::BIGINT, Types::INTEGER],
            );

            return [$replaced, $variants, $total];
        }, 'variant');
    }

    /**
     * The variant list of a row (JSON), keeping only well-formed entries.
     *
     * @return array<string, array{key: string, size: int, width: int, height: int, mime: string}>
     */
    private static function variants(mixed $json): array
    {
        $decoded  = is_string($json) ? json_decode($json, true) : $json;
        $variants = [];
        foreach (is_array($decoded) ? $decoded : [] as $name => $variant) {
            if (is_string($name) && is_array($variant) && is_string($variant['key'] ?? null) && is_int($variant['size'] ?? null) && is_int($variant['width'] ?? null) && is_int($variant['height'] ?? null) && is_string($variant['mime'] ?? null)) {
                $variants[$name] = ['key' => $variant['key'], 'size' => $variant['size'], 'width' => $variant['width'], 'height' => $variant['height'], 'mime' => $variant['mime']];
            }
        }

        return $variants;
    }

    /**
     * The SHA-256 of the open source, read in chunks; its size must not have changed.
     *
     * @param resource $handle
     *
     * @throws UploadRefused `source` when fewer or more bytes than declared were read
     */
    private function sha256($handle, int $size): string
    {
        rewind($handle);
        $context = hash_init('sha256');
        if (hash_update_stream($context, $handle) !== $size) {
            throw new UploadRefused(UploadRefused::SOURCE);
        }

        return hash_final($context);
    }

    /**
     * The view of a file whose variants were just written.
     *
     * @param array<string, array{key: string, size: int, width: int, height: int, mime: string}> $variants
     */
    private function view(StoredFile $file, array $variants, int $total): StoredFileView
    {
        $public = [];
        foreach ($variants as $name => $variant) {
            $public[$name] = ['width' => $variant['width'], 'height' => $variant['height'], 'mime' => $variant['mime'], 'size' => $variant['size']];
        }

        return new StoredFileView(
            $file->getUuid(),
            $file->getProfile()->getScope(),
            $file->getContextType(),
            $file->getContextId(),
            $file->getOriginalName(),
            $file->getMimeType(),
            $file->getSize(),
            $file->getWidth(),
            $file->getHeight(),
            $file->getSha256(),
            $total,
            $public,
            $file->getOwner(),
            $file->getUploadedBy(),
            $file->getCreatedAt(),
        );
    }

    private function discard(StorageConnection $connection, string $key): void
    {
        try {
            $connection->delete($key);
        } catch (Throwable) {
            $this->logger->error('Could not remove an orphan object {key}.', ['key' => $key]);
        }
    }
}
