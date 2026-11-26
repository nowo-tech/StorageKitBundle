<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use InvalidArgumentException;

use function fclose;
use function stream_get_contents;

/**
 * Default {@see ImageThumbnailerInterface} (2.2): the bundle's GD pipeline ({@see ImageProcessor::thumbnail()}) on a
 * local source of at most `upload.files.max_bytes` bytes, read once and freed with the call. GD images are destroyed
 * when the call returns (FrankenPHP worker mode: nothing is kept on the service).
 */
final readonly class GdImageThumbnailer implements ImageThumbnailerInterface
{
    /**
     * @param int $maxBytes largest source read (`upload.files.max_bytes`)
     */
    public function __construct(
        private ImageProcessor $processor,
        private int $maxBytes = FileUploader::MAX_BYTES,
    ) {
    }

    /**
     * The thumbnail (see the interface).
     *
     * @throws InvalidArgumentException when `$maxEdge` is outside {@see self::EDGE_MIN}..{@see self::EDGE_MAX}
     */
    public function thumbnail(StoredFileSource $source, int $maxEdge): ProcessedImage
    {
        if ($maxEdge < self::EDGE_MIN || $maxEdge > self::EDGE_MAX) {
            throw new InvalidArgumentException('A thumbnail edge must be 16 to 4096 pixels.');
        }
        if ($source->size === 0) {
            throw new UploadRefused(UploadRefused::EMPTY);
        }
        if ($source->size > $this->maxBytes) {
            throw new UploadRefused(UploadRefused::SIZE);
        }
        $handle = $source->open();
        try {
            $bytes = (string) stream_get_contents($handle, $this->maxBytes + 1);
        } finally {
            fclose($handle);
        }

        return $this->processor->thumbnail($bytes, $maxEdge, $this->maxBytes);
    }
}
