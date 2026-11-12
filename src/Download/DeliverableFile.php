<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

use Closure;
use LogicException;
use RuntimeException;

use function sprintf;

/**
 * A download ready to be served, as a bundle value object (1.5, architecture bar rule 3): what {@see FileDeliveryInterface}
 * returns instead of an HttpFoundation `Response`. An HTTP adapter turns it into a response —
 * {@see FileDeliveryResponder} for HttpFoundation; a PSR-7 or other adapter reads the same values:
 *
 * - a **redirect** (`302`) to {@see self::location()}, a pre-signed URL of the private bucket, or
 * - a **stream** (`200`) of exactly {@see self::$size} bytes written by {@see self::writeTo()} (the bucket object is read
 *   with a byte range and sent as it arrives; a bucket that sends more is cut there and the write fails);
 * - in both cases {@see self::$headers} first, then a private cache of {@see self::$maxAge} seconds, then
 *   {@see self::$policyHeaders}.
 *
 * The pre-signed URL (a bearer credential until it expires) and the body source (a closure over the bucket
 * connection, which holds the bucket credentials) are not stored in a property of this object: they live in an
 * internal {@see \WeakMap} keyed by it, so a dump (VarDumper / `dump()`), `var_export()`, `print_r()`, `(array)` cast or
 * `json_encode()` shows neither. It cannot be cloned or serialized. The entry dies with the object, so nothing outlives
 * the request in a FrankenPHP worker (REQ-RUNTIME-002).
 */
final readonly class DeliverableFile
{
    /** A `302` redirect to a pre-signed URL. */
    public const REDIRECT = 'redirect';

    /** A `200` streamed body. */
    public const STREAM = 'stream';

    /** Headers every download ends with (after the cache headers). */
    public const POLICY_HEADERS = ['X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer'];

    /**
     * @param string $mode {@see self::REDIRECT} or {@see self::STREAM}
     * @param int $statusCode `302` or `200`
     * @param array<string, string> $headers headers of the response, in order (a stream's content headers)
     * @param int $maxAge seconds of the private cache (`Cache-Control: max-age=<n>, private`)
     * @param int|null $size bytes of a stream, null for a redirect
     * @param array<string, string> $policyHeaders headers set after the cache headers
     */
    private function __construct(
        public string $mode,
        public int $statusCode,
        public array $headers,
        public int $maxAge,
        public ?int $size,
        public array $policyHeaders = self::POLICY_HEADERS,
    ) {
    }

    /**
     * A `302` redirect to `$location` (kept out of the object's properties).
     *
     * @param array<string, string> $headers
     */
    public static function redirect(string $location, int $maxAge, array $headers = []): self
    {
        $file = new self(self::REDIRECT, 302, $headers, $maxAge, null);
        DeliverableFileData::put($file, $location, null);

        return $file;
    }

    /**
     * A `200` stream of `$size` bytes from `$body` (called once per {@see self::writeTo()}; kept out of the object's
     * properties).
     *
     * @param Closure(): iterable<string> $body
     * @param array<string, string> $headers
     */
    public static function stream(Closure $body, int $size, int $maxAge, array $headers = []): self
    {
        $file = new self(self::STREAM, 200, $headers, $maxAge, $size);
        DeliverableFileData::put($file, null, $body);

        return $file;
    }

    /** Whether this is a redirect (else a stream). */
    public function isRedirect(): bool
    {
        return $this->mode === self::REDIRECT;
    }

    /**
     * The pre-signed URL of a redirect. Never log it: it grants access to the object until it expires.
     *
     * @throws LogicException when this is a stream
     */
    public function location(): string
    {
        return DeliverableFileData::location($this);
    }

    /**
     * Writes the body of a stream through `$write`, chunk by chunk, at most {@see self::$size} bytes; returns the bytes
     * written.
     *
     * @param callable(string): void $write
     *
     * @throws LogicException when this is a redirect
     * @throws RuntimeException when the bucket sent more than the stored size (the rest is not sent)
     */
    public function writeTo(callable $write): int
    {
        return FileDelivery::copy((DeliverableFileData::body($this))(), (int) $this->size, $write);
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException(sprintf('%s cannot be serialized: it carries a pre-signed URL or a bucket stream.', self::class));
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException(sprintf('%s cannot be unserialized: it carries a pre-signed URL or a bucket stream.', self::class));
    }

    /**
     * Not cloneable: a copy would carry no data (`clone` fails before this body runs).
     *
     * @codeCoverageIgnore
     */
    private function __clone()
    {
    }
}
