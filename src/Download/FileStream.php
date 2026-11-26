<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

use Generator;
use LogicException;
use RuntimeException;

use function max;
use function sprintf;
use function strlen;
use function substr;

/**
 * A bounded server-side read stream of one stored object (2.2, {@see FileReaderInterface}): for a scanner or a preview
 * builder that reads the bytes on the server. At most {@see self::$size} bytes are ever returned; a bucket that sends
 * more makes {@see self::read()} fail at that point (the rest is not read). Close it in a `finally`
 * ({@see self::close()}; the destructor closes it too): the bucket response or the local handle is released at once.
 *
 * It holds no credential in a property (the body source is a generator over the bucket connection) and cannot be
 * cloned or serialized. Nothing outlives the call that opened it (FrankenPHP worker mode, REQ-RUNTIME-002).
 */
final class FileStream
{
    /** Bytes of the stream (the stored size, or the part asked for). */
    public readonly int $size;

    /** The stored media type of the object (as uploaded, never trusted for rendering). */
    public readonly string $mediaType;

    /** @var Generator<int, string>|null */
    private ?Generator $chunks;

    private string $buffer = '';

    private int $returned = 0;

    /** Whether the current chunk was taken (the source moves on at the next read). */
    private bool $advance = false;

    /**
     * @param iterable<string> $chunks the object's bytes, in order
     */
    public function __construct(iterable $chunks, int $size, string $mediaType)
    {
        $this->size      = $size;
        $this->mediaType = $mediaType;
        // @igor-ignore - The generator belongs to this per-call stream and is released by close().
        $this->chunks = (static function () use ($chunks): Generator {
            yield from $chunks;
        })();
    }

    /**
     * Closes the stream if the caller did not.
     */
    // @igor-ignore - Per-call read stream (a value object, never a service): closes its own source when dropped.
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Up to `$length` more bytes; '' at the end.
     *
     * @throws LogicException when the stream is closed
     * @throws RuntimeException when the bucket sent more than {@see self::$size} bytes
     */
    public function read(int $length = 65536): string
    {
        $chunks = $this->chunks ?? throw new LogicException('The file stream is closed.');
        while (strlen($this->buffer) < $length && $this->more($chunks)) {
            // @igor-ignore - Per-call read stream (a value object, never a service): its cursor lives as long as the stream.
            $this->buffer .= (string) $chunks->current();
            // The next chunk is pulled only when it is needed (never one past the bound).
            // @igor-ignore - Per-call read stream (a value object, never a service): its cursor lives as long as the stream.
            $this->advance = true;
            if ($this->returned + strlen($this->buffer) > $this->size) {
                $this->close();

                throw new RuntimeException('The stored object is larger than the size recorded at upload; the read was stopped.');
            }
        }
        $out = substr($this->buffer, 0, max(0, $length));
        // @igor-ignore - Per-call read stream (a value object, never a service): its cursor lives as long as the stream.
        $this->buffer = substr($this->buffer, strlen($out));
        // @igor-ignore - Per-call read stream (a value object, never a service): its cursor lives as long as the stream.
        $this->returned += strlen($out);

        return $out;
    }

    /**
     * Whether every byte was returned (or the stream is closed).
     */
    public function eof(): bool
    {
        return !$this->chunks instanceof Generator || ($this->buffer === '' && !$this->more($this->chunks));
    }

    /**
     * Whether the source has another chunk (moving past the one already taken).
     *
     * @param Generator<int, string> $chunks
     */
    private function more(Generator $chunks): bool
    {
        if ($this->advance) {
            // @igor-ignore - Per-call read stream (a value object, never a service): its cursor lives as long as the stream.
            $this->advance = false;
            $chunks->next();
        }

        return $chunks->valid();
    }

    /**
     * Releases the bucket response or the local handle. Idempotent.
     */
    public function close(): void
    {
        $this->chunks = null;
        $this->buffer = '';
    }

    /**
     * Not cloneable: two readers of one bucket response would split its bytes.
     *
     * @throws LogicException always
     */
    public function __clone()
    {
        throw new LogicException(sprintf('%s cannot be cloned.', self::class));
    }

    /**
     * @throws LogicException always
     *
     * @return array<never, never>
     */
    public function __serialize(): array
    {
        throw new LogicException(sprintf('%s cannot be serialized: it reads a bucket object.', self::class));
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws LogicException always
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException(sprintf('%s cannot be unserialized: it reads a bucket object.', self::class));
    }
}
