<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Download;

use Closure;
use LogicException;
use WeakMap;

/**
 * Holds the sensitive part of each {@see DeliverableFile} outside the object: the pre-signed URL of a redirect (a bearer
 * credential for the bucket object until it expires) and the body source of a stream (a closure over the bucket
 * connection, whose client holds the bucket credentials). No dump of the VO can then reach them.
 *
 * Keyed by the VO in a {@see WeakMap}: an entry disappears with its VO, so nothing outlives the request in a FrankenPHP
 * worker with kernel reset false (no reset needed, REQ-RUNTIME-002).
 *
 * @internal
 */
final class DeliverableFileData
{
    /** @var WeakMap<DeliverableFile, array{location: string|null, body: (Closure(): iterable<string>)|null}>|null */
    private static ?WeakMap $values = null; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (WeakMap keyed by the per-request VO)

    /**
     * Stores the hidden values of a VO.
     *
     * @param (Closure(): iterable<string>)|null $body
     */
    public static function put(DeliverableFile $file, ?string $location, ?Closure $body): void
    {
        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$values ??= new WeakMap(); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$values[$file] = ['location' => $location, 'body' => $body]; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
    }

    /**
     * The pre-signed URL of a redirect.
     *
     * @throws LogicException when the VO is a stream (or carries no data)
     */
    public static function location(DeliverableFile $file): string
    {
        return self::$values[$file]['location'] ?? throw new LogicException('This deliverable file is not a redirect.');
    }

    /**
     * The body source of a stream.
     *
     * @throws LogicException when the VO is a redirect (or carries no data)
     *
     * @return Closure(): iterable<string>
     */
    public static function body(DeliverableFile $file): Closure
    {
        return self::$values[$file]['body'] ?? throw new LogicException('This deliverable file is not a stream.');
    }
}
