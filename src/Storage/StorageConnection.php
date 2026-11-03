<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Storage;

use AsyncAws\S3\S3Client;
use DateTimeImmutable;
use League\Flysystem\FilesystemOperator;

use function fclose;
use function fread;
use function min;
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
     * Reads an object.
     */
    public function read(string $key): string
    {
        return $this->filesystem->read($key);
    }

    /**
     * The first `$length` bytes of an object, as chunks (audit CJ L2b). On S3 it is a `GetObject` with
     * `Range: bytes=0-<length-1>` whose body is iterated as it arrives: a bucket that serves a larger object (or ignores
     * the range) cannot make the application download more than the caller reads, and nothing is fetched before the
     * first chunk. Without an S3 client (tests), the object stream is read in chunks, one byte past `$length` at most so
     * the caller can see an object that grew.
     *
     * @return iterable<string>
     */
    public function readRange(string $key, int $length): iterable
    {
        if ($length <= 0) {
            return;
        }
        if ($this->client instanceof S3Client) {
            yield from $this->client->getObject([
                'Bucket' => $this->settings->bucket,
                'Key'    => $this->settings->key($key),
                'Range'  => 'bytes=0-' . ($length - 1),
            ])->getBody()->getChunks();

            return;
        }
        $stream = $this->filesystem->readStream($key);
        try {
            $left = $length + 1;
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
