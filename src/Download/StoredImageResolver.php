<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Reference\FileUris;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;

/**
 * For a Markdown renderer: the stored images of a text the viewer may see — one query for the files and one batched
 * access check per context type; an image of a context the viewer may not read is left out (render a neutral "not
 * visible" placeholder for it).
 */
final readonly class StoredImageResolver implements StoredImageResolverInterface
{
    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private StoredFileRepository $files,
        private FileContexts $contexts,
        private FileUrls $urls,
        private FileUris $uris,
    ) {
    }

    /**
     * The visible images among `$uuids`.
     *
     * @param list<string> $uuids
     * @param object|null $viewer security user
     *
     * @return array<string, StoredImage> uuid => image
     */
    public function resolve(array $uuids, ?object $viewer): array
    {
        $files    = $this->files->byUuids($uuids);
        $readable = $this->contexts->readable($viewer, array_values($files));
        $images   = [];
        foreach ($files as $uuid => $file) {
            if (($readable[$uuid] ?? false) !== true) {
                continue;
            }
            $preview       = $file->variant(StoredFile::VARIANT_PREVIEW);
            $images[$uuid] = new StoredImage(
                $this->urls->download($uuid, StoredFile::VARIANT_PREVIEW),
                $this->urls->download($uuid, StoredFile::VARIANT_ORIGINAL),
                $preview['width'],
                $preview['height'],
                $file->getOriginalName(),
            );
        }

        return $images;
    }

    /**
     * The visible images a text points at.
     *
     * @return array<string, StoredImage>
     */
    public function resolveText(string $text, ?object $viewer): array
    {
        return $this->resolve($this->uris->storedIds($text), $viewer);
    }
}
