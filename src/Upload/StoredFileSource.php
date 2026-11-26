<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Upload;

use InvalidArgumentException;
use SensitiveParameter;

use function clearstatcache;
use function fclose;
use function filesize;
use function fopen;
use function fstat;
use function is_file;
use function is_link;
use function str_contains;

/**
 * The bytes of an upload as a local file (2.2, generic files): the path of a file the caller wrote (a temporary upload,
 * `tempnam()`), and its size in bytes. StorageKit streams it to the bucket and hashes it in chunks, so a 25 MiB file is
 * never held in a PHP string ({@see FileUploaderInterface::uploadFile()}); the thumbnailer reads at most its own limit
 * ({@see ImageThumbnailerInterface}).
 *
 * The path is the caller's (never user input): it must name a regular file, not a symbolic link. The size is checked
 * again against the open file when it is read; a file that changed meanwhile is refused ({@see UploadRefused::SOURCE}).
 * The VO holds no handle: nothing outlives the call (FrankenPHP worker mode).
 */
final readonly class StoredFileSource
{
    /**
     * @param string $path absolute path of a local regular file
     * @param int $size its size in bytes (0 or more)
     *
     * @throws InvalidArgumentException on an empty path, a NUL byte in it or a negative size
     */
    public function __construct(
        #[SensitiveParameter]
        public string $path,
        public int $size,
    ) {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('A stored file source needs the path of a local file.');
        }
        if ($size < 0) {
            throw new InvalidArgumentException('A stored file source cannot have a negative size.');
        }
    }

    /**
     * The source of the local file `$path`, with its current size.
     *
     * @throws InvalidArgumentException when `$path` is not a regular file (or is a symbolic link)
     */
    public static function fromPath(string $path): self
    {
        if ($path === '' || str_contains($path, "\0") || is_link($path) || !is_file($path)) {
            throw new InvalidArgumentException('A stored file source must be a regular local file.');
        }
        clearstatcache(true, $path);

        return new self($path, (int) filesize($path));
    }

    /**
     * Opens the file for reading and checks it is still a regular file (not a symbolic link) of {@see self::$size}
     * bytes. The caller closes the handle (in a `finally`).
     *
     * @throws UploadRefused `source` when it cannot be opened, is not a regular file or its size changed
     *
     * @return resource
     */
    public function open()
    {
        $handle = is_link($this->path) ? false : @fopen($this->path, 'r');
        if ($handle === false) {
            throw new UploadRefused(UploadRefused::SOURCE);
        }
        $stat = fstat($handle);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== $this->size) {
            fclose($handle);

            throw new UploadRefused(UploadRefused::SOURCE);
        }

        return $handle;
    }
}
