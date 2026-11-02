<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Storage;

use function intdiv;

/**
 * Outcome of *Test connection*: the step that failed (endpoint, write, read, presign, delete), a generic reason
 * (translated as `probe.reason.<reason>`), the HTTP status class of the storage's answer when there was one, and
 * whether an anonymous read of the probe object succeeded (a public bucket: a warning). The provider's message or
 * response body is never kept here (SEC-STORAGE-SSRF: no response oracle), only logged without credentials.
 */
final readonly class StorageProbeResult
{
    /** Step: the endpoint check. */
    public const STEP_ENDPOINT = 'endpoint';

    /** Step: writing the probe object. */
    public const STEP_WRITE = 'write';

    /** Step: reading it back. */
    public const STEP_READ = 'read';

    /** Step: pre-signing it. */
    public const STEP_PRESIGN = 'presign';

    /** Step: deleting it. */
    public const STEP_DELETE = 'delete';

    /** Reason: the endpoint is refused by the guard. */
    public const REASON_ENDPOINT = 'endpoint';

    /** Reason: the storage answered with an HTTP error. */
    public const REASON_HTTP = 'http';

    /** Reason: the storage could not be reached. */
    public const REASON_UNREACHABLE = 'unreachable';

    /** Reason: the object read back differs from the one written. */
    public const REASON_MISMATCH = 'mismatch';

    /** Reason: anything else. */
    public const REASON_OTHER = 'other';

    /**
     * @param string|null $failedStep null = every step passed
     */
    public function __construct(
        public ?string $failedStep = null,
        public string $reason = '',
        public ?int $httpStatus = null,
        public bool $publicRead = false,
    ) {
    }

    /**
     * Every step passed.
     */
    public function ok(): bool
    {
        return $this->failedStep === null;
    }

    /**
     * The status class of the storage's answer (`4xx`, `5xx`…), or null without one.
     */
    public function statusClass(): ?string
    {
        return $this->httpStatus === null ? null : intdiv($this->httpStatus, 100) . 'xx';
    }
}
