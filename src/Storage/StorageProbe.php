<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Storage;

use DateTimeImmutable;
use Nowo\StorageKitBundle\Security\EndpointGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function rtrim;

/**
 * *Test connection*: writes a small probe object, reads it back, pre-signs it, tries an anonymous read (success = the
 * bucket allows public reads: a warning, files must stay private) and deletes it. SEC-STORAGE-SSRF: a restricted
 * profile's endpoint is checked by {@see EndpointGuard} first and every request goes through its guarded client; the
 * outcome is a generic reason plus the HTTP status class — the provider's message (scrubbed) only goes to the log.
 */
final readonly class StorageProbe
{
    /** Directory of the probe objects under the prefix. */
    public const PROBE_DIRECTORY = '.storage-kit-probe';

    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private StorageConnectionFactoryInterface $factory,
        private EndpointGuard $guard,
        private ?HttpClientInterface $httpClient = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Runs the probe.
     */
    public function run(S3Settings $settings): StorageProbeResult
    {
        if ($this->guard->checkSettings($settings) !== null) {
            return new StorageProbeResult(StorageProbeResult::STEP_ENDPOINT, StorageProbeResult::REASON_ENDPOINT);
        }
        $key  = self::PROBE_DIRECTORY . '/' . Uuid::v4()->toRfc4122();
        $body = 'storage-kit probe ' . $key;
        $step = StorageProbeResult::STEP_WRITE;
        try {
            $connection = $this->factory->connect($settings);
            $connection->write($key, $body, 'text/plain');
            $step = StorageProbeResult::STEP_READ;
            if ($connection->read($key) !== $body) {
                $connection->delete($key);

                return new StorageProbeResult($step, StorageProbeResult::REASON_MISMATCH);
            }
            $step = StorageProbeResult::STEP_PRESIGN;
            $connection->presign($key, 'text/plain', 'attachment', new DateTimeImmutable('+5 minutes'));
            $publicRead = $this->publicRead($settings, $key);
            $step       = StorageProbeResult::STEP_DELETE;
            $connection->delete($key);

            return new StorageProbeResult(publicRead: $publicRead);
        } catch (Throwable $e) {
            $this->logger->warning('Storage test connection failed at {step}: {error}', [
                'step'  => $step,
                'error' => ErrorScrubber::message($e, $settings),
            ]);
            [$reason, $status] = ErrorScrubber::reason($e);

            return new StorageProbeResult($step, $reason, $status);
        }
    }

    /**
     * An unsigned GET of the probe through the server-side endpoint (path style): 200 means anyone can read the
     * bucket. Same guarded client as the S3 requests.
     */
    private function publicRead(S3Settings $settings, string $key): bool
    {
        $base = $settings->endpoint ?? 'https://s3.' . $settings->region . '.amazonaws.com';
        $url  = rtrim($base, '/') . '/' . $settings->bucket . '/' . $settings->key($key);
        try {
            return $this->guard->httpClient($this->httpClient, $settings)->request('GET', $url, ['timeout' => 5, 'max_redirects' => 0])->getStatusCode() === 200;
        } catch (HttpClientException) {
            return false;
        }
    }
}
