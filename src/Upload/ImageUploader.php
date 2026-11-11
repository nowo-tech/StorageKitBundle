<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use DateTimeImmutable;
use Nowo\StorageKitBundle\Audit\AuditRecorder;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Context\FileContextTarget;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StoredFile;
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
 */
final readonly class ImageUploader implements ImageUploaderInterface
{
    /** Longest kept file name. */
    public const NAME_MAX = 120;

    /**
     * Contexts, profiles, storage, checks, entity manager, audit, optional limit, logger and the stable uploader key.
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
    ) {
    }

    /**
     * Stores an image for `$user` in a context and returns the row (loaded through the bundle's entity manager).
     *
     * The `file_uploaded` audit event is recorded at once, as before 1.3.
     *
     * @deprecated since 1.3 for its entity return type: use {@see uploadImage()} (a {@see StoredFileView}); the return
     *             type changes in 2.0
     *
     * @param object $user security user (writes are checked by the context)
     *
     * @throws UploadRefused
     */
    public function upload(object $user, string $contextType, string $contextId, string $bytes, string $originalName): StoredFile
    {
        $file = $this->store($user, $contextType, $contextId, $bytes, $originalName);
        $this->record($file->getProfile()->getScope(), $user, $file->getUuid(), $contextType, $contextId, $file->totalBytes());
        $id = $file->getId();

        return ($id === null ? null : $this->entityManager->manager()->find(StoredFile::class, $id)) ?? $file;
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
    private function store(object $user, string $contextType, string $contextId, string $bytes, string $originalName): StoredFile
    {
        $target = FileContexts::isType($contextType) && FileContexts::isId($contextId)
            ? $this->contexts->get($contextType)?->writableTarget($user, $contextId)
            : null;
        if (!$target instanceof FileContextTarget) {
            throw new UploadRefused(UploadRefused::CONTEXT);
        }
        $profile  = $this->profiles->forTarget($target) ?? throw new UploadRefused(UploadRefused::OFF);
        $settings = $this->profiles->settingsOf($profile) ?? throw new UploadRefused(UploadRefused::OFF);
        $image    = $this->processor->process($bytes);
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
            $file->setUploadedBy($user instanceof UserInterface ? mb_substr($user->getUserIdentifier(), 0, 180) : null);
            $file->setUploadedByKey($this->validKey($this->uploaderKeys->keyOf($user)));
            $file->setOwner($target->owner);
            $file->markOrphaned(new DateTimeImmutable());
            $this->entityManager->insert($file);
        } catch (Throwable $e) {
            $this->discard($connection, $written);
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
     * A resolver's key, or null when it is empty or too long (never cut: a cut key could match another person).
     */
    private function validKey(?string $key): ?string
    {
        return $key === null || $key === '' || strlen($key) > UploaderKeyResolverInterface::MAX_LENGTH ? null : $key;
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
