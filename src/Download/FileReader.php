<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Storage\StorageConnection;

use function in_array;
use function max;
use function min;

/**
 * Default {@see FileReaderInterface} (2.2): the row by exact public id and context
 * ({@see StoredFileRepository::findInContext()}), then a ranged read of the object
 * ({@see StorageConnection::readRange()}) wrapped in a {@see FileStream}. Stateless.
 */
final readonly class FileReader implements FileReaderInterface
{
    /**
     * Dependencies.
     */
    public function __construct(
        private StoredFileRepository $files,
        private StorageProfiles $profiles,
        private FileStorage $storage,
    ) {
    }

    /**
     * Opens a variant (see the interface).
     */
    public function open(string $uuid, string $contextType, string $contextId, string $variant, ?int $length = null): FileStream
    {
        if (!in_array($variant, StoredFile::VARIANTS, true)) {
            throw new FileNotDeliverable('Unknown file variant.');
        }
        $file     = $this->files->findInContext($uuid, $contextType, $contextId) ?? throw new FileNotDeliverable('No such file.');
        $object   = $file->servedVariant($variant) ?? throw new FileNotDeliverable('No such file variant.');
        $settings = $this->profiles->settingsOf($file->getProfile()) ?? throw new FileNotDeliverable('The bucket of this file is not connectable.');
        $size     = $length === null ? $object['size'] : min($object['size'], max(0, $length));

        // A part of the object is read to its last byte; the whole object one byte past it (an object that grew fails).
        return new FileStream($this->storage->connection($settings)->readRange($object['key'], $size, 0, $size === $object['size']), $size, $object['mime']);
    }
}
