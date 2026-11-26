<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use RuntimeException;

/**
 * An upload refused before anything is stored: the reason is a stable code the controller turns into a status and a
 * translated message (`upload.error.<reason>`).
 */
final class UploadRefused extends RuntimeException
{
    /** Not a PNG, JPEG, GIF or WebP image (by content); 2.2: a generic file type the context does not accept, or a declared image type its bytes do not match. */
    public const TYPE = 'type';

    /** Larger than `upload.max_bytes` (2.2: generic files, the context's limit or `upload.files.max_bytes`). */
    public const SIZE = 'size';

    /** More than `upload.max_pixels`, or too large to decode in the memory left. */
    public const PIXELS = 'pixels';

    /** No file. */
    public const EMPTY = 'empty';

    /** Unknown context, or the user may not write in it. */
    public const CONTEXT = 'context';

    /** No usable bucket for the context (1.6: or, for square images, GD without WebP support). */
    public const OFF = 'off';

    /** The owner's storage limit is reached ({@see UploadLimitInterface}). */
    public const LIMIT = 'limit';

    /** The bucket failed. */
    public const STORAGE = 'storage';

    /** Bad or missing CSRF token. */
    public const CSRF = 'csrf';

    /** Too many uploads. */
    public const RATE = 'rate';

    /** Not allowed to upload at all. */
    public const ACCESS = 'access';

    /** 2.2: the local source file of a generic upload cannot be read, or its size changed ({@see StoredFileSource}). */
    public const SOURCE = 'source';

    /**
     * @param string $reason one of the constants
     */
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct($reason);
    }
}
