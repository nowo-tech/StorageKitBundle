<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Storage;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Entry point to object storage: one memoised {@see StorageConnection} per bucket settings for the request (worker
 * mode: {@see self::reset()} drops them between requests, `kernel.reset`). Nothing is written to the application's
 * disk.
 */
final class FileStorage implements ResetInterface
{
    /** @var array<string, StorageConnection> */
    private array $connections = [];

    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private readonly StorageConnectionFactoryInterface $factory,
    ) {
    }

    /**
     * The connection of a bucket (opened once per request).
     */
    public function connection(S3Settings $settings): StorageConnection
    {
        // @igor-ignore - Request-scoped memo, cleared by reset() (kernel.reset).
        return $this->connections[$settings->fingerprint()] ??= $this->factory->connect($settings);
    }

    /**
     * Drops the memoised connections.
     */
    public function reset(): void
    {
        $this->connections = [];
    }
}
