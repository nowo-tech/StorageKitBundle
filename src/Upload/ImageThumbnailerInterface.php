<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

/**
 * The image pipeline as a port (2.2): a bounded, re-encoded thumbnail of a raster image, for host code that builds
 * previews (ChatKit image attachments, unfurl images) without a second image stack. Aliased to
 * {@see GdImageThumbnailer}.
 *
 * The same checks as an image upload apply: the type is read from the magic bytes (PNG, JPEG, GIF, WebP; never the
 * name or a declared type), the pixel count from the header **before** decoding (`upload.max_pixels`), and the decoded
 * size must fit in what is left of `memory_limit` (a progressive JPEG's decoder buffer included). The output is always
 * re-encoded (EXIF / GPS / XMP and trailing payloads dropped; a JPEG turned upright first; a GIF becomes a PNG of its
 * first frame), with its longest side at most `$maxEdge` pixels (never enlarged).
 */
interface ImageThumbnailerInterface
{
    /** Smallest accepted `$maxEdge`. */
    public const EDGE_MIN = 16;

    /** Largest accepted `$maxEdge`. */
    public const EDGE_MAX = 4096;

    /**
     * The thumbnail, as {@see ProcessedImage::$original} (no variants).
     *
     * @param int $maxEdge longest side of the result, {@see self::EDGE_MIN} to {@see self::EDGE_MAX} pixels
     *
     * @throws UploadRefused `empty`, `size` (above `upload.files.max_bytes`), `type`, `pixels` or `source`
     */
    public function thumbnail(StoredFileSource $source, int $maxEdge): ProcessedImage;
}
