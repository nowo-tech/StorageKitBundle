<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Storage;

use DateTimeImmutable;
use League\Flysystem\FilesystemOperator;

/**
 * One bucket through Flysystem (write, read, delete, exists, list — keys are relative to the profile's prefix) and its
 * pre-signed download URLs. Objects are written private ({@see PrivateVisibilityConverter}).
 */
final readonly class StorageConnection
{
    /**
     * @param FilesystemOperator $filesystem the bucket, rooted at the prefix
     */
    public function __construct(
        private FilesystemOperator $filesystem,
        private UrlPresignerInterface $presigner,
        private S3Settings $settings,
    ) {
    }

    /**
     * Writes an object with its content type.
     */
    public function write(string $key, string $contents, string $contentType): void
    {
        $this->filesystem->write($key, $contents, ['ContentType' => $contentType, 'mimetype' => $contentType]);
    }

    /**
     * Reads an object.
     */
    public function read(string $key): string
    {
        return $this->filesystem->read($key);
    }

    /**
     * Whether an object exists.
     */
    public function exists(string $key): bool
    {
        return $this->filesystem->fileExists($key);
    }

    /**
     * Deletes an object (no error when it is already gone).
     */
    public function delete(string $key): void
    {
        $this->filesystem->delete($key);
    }

    /**
     * Object keys under the prefix, relative to it, at most `$limit` of them (bounded listing).
     *
     * @return iterable<string>
     */
    public function keys(int $limit): iterable
    {
        $count = 0;
        foreach ($this->filesystem->listContents('', true) as $item) {
            if (!$item->isFile()) {
                continue;
            }
            if ($count >= $limit) {
                return;
            }
            ++$count;
            yield $item->path();
        }
    }

    /**
     * A pre-signed GET URL of an object (relative key).
     */
    public function presign(string $key, string $contentType, string $disposition, DateTimeImmutable $expires): string
    {
        return $this->presigner->presign($this->settings->key($key), $contentType, $disposition, $expires);
    }

    /**
     * The settings this connection was opened with.
     */
    public function settings(): S3Settings
    {
        return $this->settings;
    }
}
