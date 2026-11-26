<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Storage;

use AsyncAws\S3\S3Client;
use DateTimeImmutable;
use League\Flysystem\FilesystemOperator;
use RuntimeException;

use function fclose;
use function fread;
use function min;
use function str_starts_with;
use function strlen;

/**
 * One bucket through Flysystem (write, read, delete, exists, list — keys are relative to the profile's prefix) and its
 * pre-signed download URLs. Objects are written private ({@see PrivateVisibilityConverter}).
 */
final readonly class StorageConnection
{
    /** Bytes per chunk of {@see self::readRange()} without an S3 client. */
    public const CHUNK = 65536;

    /**
     * @param FilesystemOperator $filesystem the bucket, rooted at the prefix
     * @param S3Client|null $client the S3 client of the bucket (ranged reads); null = read through Flysystem
     */
    public function __construct(
        private FilesystemOperator $filesystem,
        private UrlPresignerInterface $presigner,
        private S3Settings $settings,
        private ?S3Client $client = null,
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
     * Writes an object from a stream (2.2, generic files): the bytes go to the bucket as they are read, never as one PHP
     * string. The caller owns the handle (it is not closed here).
     *
     * @param resource $contents readable stream positioned at the first byte
     */
    public function writeStream(string $key, $contents, string $contentType): void
    {
        $this->filesystem->writeStream($key, $contents, ['ContentType' => $contentType, 'mimetype' => $contentType]);
    }

    /**
     * Reads an object as a stream (2.2: bucket moves of large files). The caller closes it.
     *
     * @return resource
     */
    public function readStream(string $key)
    {
        return $this->filesystem->readStream($key);
    }

    /**
     * Reads an object.
     */
    public function read(string $key): string
    {
        return $this->filesystem->read($key);
    }

    /**
     * `$length` bytes of an object from byte `$offset` (default: its first bytes), as chunks (audit CJ L2b; 2.2: the
     * offset serves `Range` requests). On S3 it is a `GetObject` with `Range: bytes=<offset>-<offset+length-1>` whose
     * body is iterated as it arrives: a bucket that serves a larger object (or ignores the range) cannot make the
     * application download more than the caller reads, and nothing is fetched before the first chunk. Without an S3
     * client (tests), the object stream is skipped to the offset and read in chunks, one byte past `$length` at most so
     * the caller can see an object that grew (2.2: only when `$whole`, the read of a whole object; a part of it is read
     * to its last byte exactly).
     *
     * @return iterable<string>
     */
    public function readRange(string $key, int $length, int $offset = 0, bool $whole = true): iterable
    {
        if ($length <= 0 || $offset < 0) {
            return;
        }
        if ($this->client instanceof S3Client) {
            $object = $this->client->getObject([
                'Bucket' => $this->settings->bucket,
                'Key'    => $this->settings->key($key),
                'Range'  => 'bytes=' . $offset . '-' . ($offset + $length - 1),
            ]);
            // A bucket that ignored the range would send the object from its first byte: never served as a later part.
            if ($offset > 0 && !str_starts_with((string) $object->getContentRange(), 'bytes ' . $offset . '-')) {
                throw new RuntimeException('The bucket did not honour the byte range; the download was not sent.');
            }
            yield from $object->getBody()->getChunks();

            return;
        }
        $stream = $this->filesystem->readStream($key);
        try {
            $skip = $offset;
            while ($skip > 0 && ($chunk = fread($stream, min(self::CHUNK, $skip))) !== false && $chunk !== '') {
                $skip -= strlen($chunk);
            }
            $left = $whole ? $length + 1 : $length;
            while ($left > 0 && ($chunk = fread($stream, min(self::CHUNK, $left))) !== false && $chunk !== '') {
                $left -= strlen($chunk);
                yield $chunk;
            }
        } finally {
            fclose($stream);
        }
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
