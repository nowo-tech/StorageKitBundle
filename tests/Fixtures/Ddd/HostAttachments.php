<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures\Ddd;

use Nowo\StorageKitBundle\Download\FileDeliveryInterface;
use Nowo\StorageKitBundle\Download\StoredImage;
use Nowo\StorageKitBundle\Download\StoredImageResolverInterface;
use Nowo\StorageKitBundle\PersonalData\StoredFilesPersonalDataInterface;
use Nowo\StorageKitBundle\Reference\FileReferencesInterface;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;

/**
 * The application service of a DDD host (its notes are aggregates saved by its own repositories, never Doctrine
 * entities the bundle sees): it talks to the bundle through its 1.5 ports only — interfaces in, scalars and value
 * objects out, no entity, no HttpFoundation type.
 */
final readonly class HostAttachments
{
    public function __construct(
        private ImageUploaderInterface $uploader,
        private FileReferencesInterface $references,
        private StoredImageResolverInterface $images,
        private FileDeliveryInterface $delivery,
        private StoredFilesPersonalDataInterface $personalData,
    ) {
    }

    /** Stores an image for a note of `$board`; returns its public id. */
    public function attach(object $author, string $board, string $bytes, string $name): string
    {
        return $this->uploader->uploadImage($author, 'board', $board, $bytes, $name)->uuid;
    }

    /** The host saved note `$noteId`: the bundle learns which files its text points at. */
    public function noteSaved(string $noteId, string $board, string $text): void
    {
        $this->references->sync('page', $noteId, $text, ['board', $board]);
    }

    /** The host deleted note `$noteId`. */
    public function noteDeleted(string $noteId): void
    {
        $this->references->forget('page', [$noteId]);
    }

    /**
     * @return array<string, StoredImage>
     */
    public function render(string $text, ?object $viewer): array
    {
        return $this->images->resolveText($text, $viewer);
    }

    /** The bytes of a variant, or the pre-signed URL of a redirect-delivered one. */
    public function download(string $uuid, string $variant): string
    {
        $file = $this->delivery->deliver($uuid, $variant);
        if ($file->isRedirect()) {
            return $file->location();
        }
        $bytes = '';
        $file->writeTo(static function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });

        return $bytes;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function export(string $identifier, ?string $key): array
    {
        return $this->personalData->export($identifier, $key);
    }

    public function erase(string $identifier, ?string $key): int
    {
        return $this->personalData->erase($identifier, $key);
    }
}
