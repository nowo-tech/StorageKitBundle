<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use DateTimeImmutable;
use Nowo\StorageKitBundle\Audit\AuditRecorder;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Concurrency\StorageConflicts;
use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Context\FileContextTarget;
use Nowo\StorageKitBundle\Context\SquareImageContextInterface;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\PersonalData\UploaderIdentity;
use Nowo\StorageKitBundle\PersonalData\UploaderKeyResolverInterface;
use Nowo\StorageKitBundle\PersonalData\UserIdUploaderKeyResolver;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Storage\ErrorScrubber;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Storage\StorageConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

use function basename;
use function gmdate;
use function hash;
use function mb_substr;
use function preg_replace;
use function str_replace;
use function strlen;
use function trim;

/**
 * One image upload (the PHP API behind the upload endpoint), in the order that leaves nothing behind on a refusal:
 * the context (the user may write in it), the storage (the scope's bucket or the instance one; none = off), the image
 * checks ({@see ImageProcessor}), the owner's limit ({@see UploadLimitInterface}), then the objects (original and
 * variants under a random server-generated `yyyy/mm/<uuid>` key — no user input in the key, so no path traversal) and
 * last the row. A failed write removes the objects already written. The new file is an orphan until a text that points
 * at it is saved.
 *
 * Since 1.3 (architecture bar rule 6) the row is written with a DBAL insert built from the entity metadata (in a
 * savepoint inside an open transaction): no entity manager is flushed. Type-hint {@see ImageUploaderInterface}.
 * Since 1.6 it also stores square images ({@see SquareImageUploaderInterface}, checks of {@see SquareImageProcessor}).
 * 2.0 removed the entity-returning `upload()` (deprecated since 1.3): call {@see self::uploadImage()}.
 */
final readonly class ImageUploader implements ImageUploaderInterface, SquareImageUploaderInterface
{
    /** Longest kept file name. */
    public const NAME_MAX = 120;

    private SquareImageProcessor $squareProcessor;

    /**
     * Contexts, profiles, storage, checks, entity manager, audit, optional limit, logger, the stable uploader key and
     * the square image checks (1.6; default: {@see SquareImageProcessor} with its defaults).
     */
    public function __construct(
        private FileContexts $contexts,
        private StorageProfiles $profiles,
        private FileStorage $storage,
        private ImageProcessor $processor,
        private StorageEntityManager $entityManager,
        private AuditRecorder $audit,
        private ?UploadLimitInterface $limit = null,
        private LoggerInterface $logger = new NullLogger(),
        private UploaderKeyResolverInterface $uploaderKeys = new UserIdUploaderKeyResolver(),
        ?SquareImageProcessor $squareProcessor = null,
    ) {
        $this->squareProcessor = $squareProcessor ?? new SquareImageProcessor();
    }

    /**
     * Stores an image and returns its view; the audit event follows the commit (see the interface).
     *
     * @throws UploadRefused
     */
    public function uploadImage(object $user, string $contextType, string $contextId, string $bytes, string $originalName): StoredFileView
    {
        $view = StoredFileView::fromEntity($this->store($user, $contextType, $contextId, $bytes, $originalName));
        if (!$this->entityManager->connection()->isTransactionActive()) {
            $this->afterCommit($view);
        }

        return $view;
    }

    /**
     * Stores a square image (1.6) and returns its view; the audit event follows the commit like {@see uploadImage()}.
     *
     * @throws UploadRefused
     */
    public function uploadSquareImage(object $user, string $contextType, string $contextId, string $bytes, string $originalName): StoredFileView
    {
        $view = StoredFileView::fromEntity($this->store($user, $contextType, $contextId, $bytes, $originalName, true));
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
            $this->record($view->scope, $view->uploadedBy, $view->uuid, $view->contextType, $view->contextId, $view->totalBytes);
        }
    }

    /**
     * The checks, the objects and the row (DBAL insert; no entity manager is flushed).
     *
     * @throws UploadRefused
     */
    private function store(object $user, string $contextType, string $contextId, string $bytes, string $originalName, bool $square = false): StoredFile
    {
        $context = FileContexts::isType($contextType) && FileContexts::isId($contextId) ? $this->contexts->get($contextType) : null;
        // 1.6: a square-only context (profile pictures, logos) takes square images only.
        $target = $context instanceof SquareImageContextInterface && !$square ? null : $context?->writableTarget($user, $contextId);
        if (!$target instanceof FileContextTarget) {
            throw new UploadRefused(UploadRefused::CONTEXT);
        }
        $profile  = $this->profiles->forTarget($target) ?? throw new UploadRefused(UploadRefused::OFF);
        $settings = $this->profiles->settingsOf($profile) ?? throw new UploadRefused(UploadRefused::OFF);
        $image    = $square ? $this->squareProcessor->process($bytes) : $this->processor->process($bytes);
        if ($this->limit instanceof UploadLimitInterface && !$this->limit->allows($target, $image->totalBytes())) {
            throw new UploadRefused(UploadRefused::LIMIT);
        }

        $uuid       = Uuid::v4()->toRfc4122();
        $key        = gmdate('Y/m') . '/' . $uuid;
        $connection = $this->storage->connection($settings);
        $written    = [];
        try {
            $connection->write($key, $image->original['bytes'], $image->original['mime']);
            $written[] = $key;
            $file      = new StoredFile(
                $profile,
                $contextType,
                $contextId,
                $key,
                self::cleanName($originalName),
                $image->original['mime'],
                strlen($image->original['bytes']),
                $image->original['width'],
                $image->original['height'],
                hash('sha256', $image->original['bytes']),
                $uuid,
            );
            foreach ($image->variants as $name => $variant) {
                $variantKey = $key . '-' . $name;
                $connection->write($variantKey, $variant['bytes'], $variant['mime']);
                $written[] = $variantKey;
                $file->addVariant($name, ['key' => $variantKey, 'size' => strlen($variant['bytes']), 'width' => $variant['width'], 'height' => $variant['height'], 'mime' => $variant['mime']]);
            }
            // House rule LQ (1.5.2): a padded, control-character or over-long identifier is not stored (never trimmed or cut).
            $file->setUploadedBy($user instanceof UserInterface ? UploaderIdentity::storable($user->getUserIdentifier()) : null);
            $file->setUploadedByKey($this->validKey($this->uploaderKeys->keyOf($user)));
            $file->setOwner($target->owner);
            $file->markOrphaned(new DateTimeImmutable());
            $this->entityManager->insert($file);
        } catch (Throwable $e) {
            $this->discard($connection, $written);
            if (StorageConflicts::isConflict($e)) {
                // 1.7 (REQ-RUNTIME-003 rule 8): a deadlock / lock wait on the insert is rethrown, never swallowed as a
                // bucket failure: the objects are gone again, so the upload endpoint answers CONFLICT_001 (retry).
                throw $e;
            }
            $this->logger->warning('Image upload failed: {error}', ['error' => ErrorScrubber::message($e, $settings)]);

            throw new UploadRefused(UploadRefused::STORAGE);
        }

        return $file;
    }

    /**
     * @param object|string|null $actor security user or its identifier
     */
    private function record(?string $scope, object|string|null $actor, string $uuid, string $contextType, string $contextId, int $bytes): void
    {
        $this->audit->record(StorageAuditEvent::FILE_UPLOADED, $scope, $actor, [
            'file'    => $uuid,
            'context' => $contextType . '/' . $contextId,
            'bytes'   => $bytes,
        ]);
    }

    /**
     * The name kept as default alt text: no path, no control characters, at most {@see self::NAME_MAX} characters.
     */
    public static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', basename(str_replace('\\', '/', $name))));

        return $name === '' ? StoredFile::ANONYMOUS_NAME : mb_substr($name, 0, self::NAME_MAX);
    }

    /**
     * A resolver's key, or null when it is empty, too long (never cut: a cut key could match another person), padded or
     * holds control / format characters (1.5.2, {@see UploaderIdentity::storable()}).
     */
    private function validKey(?string $key): ?string
    {
        return $key === null || strlen($key) > UploaderKeyResolverInterface::MAX_LENGTH ? null : UploaderIdentity::storable($key);
    }

    /**
     * @param list<string> $keys
     */
    private function discard(StorageConnection $connection, array $keys): void
    {
        foreach ($keys as $key) {
            try {
                $connection->delete($key);
            } catch (Throwable) {
                $this->logger->error('Could not remove an orphan object {key}.', ['key' => $key]);
            }
        }
    }
}
