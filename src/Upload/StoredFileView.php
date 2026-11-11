<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use DateTimeImmutable;
use Nowo\StorageKitBundle\Entity\StoredFile;

/**
 * Persistence-neutral, read-only view of a stored image (1.3, architecture bar rules 1 and 3): what
 * {@see ImageUploaderInterface::uploadImage()} returns instead of the Doctrine entity. Reference the file by its
 * {@see self::$uuid} (the `storage://<uuid>` URI and the download routes use it).
 */
final readonly class StoredFileView
{
    /**
     * @param string $uuid public id of the file
     * @param string|null $scope scope of the storage profile it was written to (null = the instance bucket)
     * @param string $contextType context type it was uploaded into
     * @param string $contextId context id it was uploaded into
     * @param string $originalName cleaned original name (default alt text)
     * @param string $mimeType type of the original
     * @param int $size bytes of the original
     * @param int $width width of the original
     * @param int $height height of the original
     * @param string $sha256 SHA-256 of the original
     * @param int $totalBytes original + variants
     * @param array<string, array{width: int, height: int, mime: string, size: int}> $variants generated variants (no object key)
     * @param string|null $owner owner of the context (limits)
     * @param string|null $uploadedBy user identifier of the uploader
     * @param DateTimeImmutable $createdAt upload time
     */
    public function __construct(
        public string $uuid,
        public ?string $scope,
        public string $contextType,
        public string $contextId,
        public string $originalName,
        public string $mimeType,
        public int $size,
        public int $width,
        public int $height,
        public string $sha256,
        public int $totalBytes,
        public array $variants,
        public ?string $owner,
        public ?string $uploadedBy,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * View of a stored file row.
     */
    public static function fromEntity(StoredFile $file): self
    {
        $variants = [];
        foreach ($file->getVariants() as $name => $variant) {
            $variants[$name] = ['width' => $variant['width'], 'height' => $variant['height'], 'mime' => $variant['mime'], 'size' => $variant['size']];
        }

        return new self(
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
            $file->totalBytes(),
            $variants,
            $file->getOwner(),
            $file->getUploadedBy(),
            $file->getCreatedAt(),
        );
    }
}
