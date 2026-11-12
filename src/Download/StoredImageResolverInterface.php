<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

/**
 * Renderer port (1.5, architecture bar rule 1): the stored images of a text the viewer may see, as {@see StoredImage}
 * value objects. Implemented by {@see StoredImageResolver} (service alias of this interface).
 */
interface StoredImageResolverInterface
{
    /**
     * The visible images among `$uuids` (an image of a context the viewer may not read is left out).
     *
     * @param list<string> $uuids
     * @param object|null $viewer the viewer as the host's file contexts know it (security user, or null for anonymous)
     *
     * @return array<string, StoredImage> uuid => image
     */
    public function resolve(array $uuids, ?object $viewer): array;

    /**
     * The visible images a text points at.
     *
     * @return array<string, StoredImage> uuid => image
     */
    public function resolveText(string $text, ?object $viewer): array;
}
