<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use function strlen;

/**
 * An accepted image ready to store: the original (re-encoded without metadata, or kept as it came for GIF / animated
 * WebP) and the generated variants (only when the image is larger than the variant size).
 *
 * @phpstan-type Encoded array{bytes: string, mime: string, width: int, height: int}
 */
final readonly class ProcessedImage
{
    /**
     * @param Encoded $original
     * @param array<string, Encoded> $variants
     */
    public function __construct(
        public array $original,
        public array $variants = [],
    ) {
    }

    /**
     * Bytes of the original and every variant.
     */
    public function totalBytes(): int
    {
        $total = strlen($this->original['bytes']);
        foreach ($this->variants as $variant) {
            $total += strlen($variant['bytes']);
        }

        return $total;
    }
}
