<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

/**
 * A stored image the viewer may see, ready for a renderer: the preview URL (`<img src>`), the original's URL (link to
 * the full size) and the preview's dimensions (no layout shift).
 */
final readonly class StoredImage
{
    /**
     * Dependencies and configuration.
     */
    public function __construct(
        public string $src,
        public string $original,
        public int $width,
        public int $height,
        public string $alt,
    ) {
    }
}
