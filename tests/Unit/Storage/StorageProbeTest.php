<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Storage;

use Nowo\StorageKitBundle\Security\EndpointGuard;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\StorageKitBundle\Storage\StorageProbe;
use Nowo\StorageKitBundle\Storage\StorageProbeResult;
use Nowo\StorageKitBundle\Tests\Support\FakeDns;
use Nowo\StorageKitBundle\Tests\Support\InMemoryBuckets;
use Nowo\StorageKitBundle\Tests\Support\InMemoryStorageConnectionFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * *Test connection*: every step, generic reasons, public-read warning, nothing secret in the log, endpoint refused
 * before any request.
 */
final class StorageProbeTest extends TestCase
{
    private InMemoryBuckets $buckets;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->buckets = new InMemoryBuckets();
        $this->log     = [];
    }

    private function probe(int $anonymousStatus = 403, bool $failAnonymous = false): StorageProbe
    {
        $http   = new MockHttpClient(static fn (): MockResponse => $failAnonymous ? new MockResponse('', ['error' => 'down']) : new MockResponse('', ['http_code' => $anonymousStatus]));
        $log    = &$this->log;
        $logger = new class($log) extends AbstractLogger {
            /**
             * @param list<string> $log
             *
             * @phpstan-ignore property.onlyWritten (written through the reference)
             */
            public function __construct(private array &$log)
            {
            }

            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->log[] = $message . ' ' . json_encode($context);
            }
        };

        return new StorageProbe(new InMemoryStorageConnectionFactory($this->buckets), new EndpointGuard([], false, false, new FakeDns()), $http, $logger);
    }

    private function settings(bool $restricted = false, ?string $endpoint = 'https://s3.example.com'): S3Settings
    {
        return new S3Settings('files', 'AKIAEXAMPLE', 'not-a-real-secret', endpoint: $endpoint, prefix: 'app', restricted: $restricted);
    }

    public function testAWorkingPrivateBucketPasses(): void
    {
        $result = $this->probe()->run($this->settings());

        self::assertTrue($result->ok());
        self::assertFalse($result->publicRead);
        self::assertSame([], $this->buckets->objects('files', 'https://s3.example.com'), 'the probe object is deleted');
    }

    public function testAPublicBucketIsReported(): void
    {
        self::assertTrue($this->probe(200)->run($this->settings(endpoint: null))->publicRead);
        self::assertFalse($this->probe(200, true)->run($this->settings())->publicRead);
    }

    public function testARefusedEndpointStopsBeforeAnyRequest(): void
    {
        $result = $this->probe()->run($this->settings(true, 'http://127.0.0.1:9000'));

        self::assertSame(StorageProbeResult::STEP_ENDPOINT, $result->failedStep);
        self::assertSame(StorageProbeResult::REASON_ENDPOINT, $result->reason);
        self::assertSame([], $this->buckets->connected);
    }

    public function testFailuresNameTheStepAndLogNoSecret(): void
    {
        $this->buckets->failWrites    = true;
        $write                        = $this->probe()->run($this->settings());
        $this->buckets->failWrites    = false;
        $this->buckets->corruptWrites = true;
        $read                         = $this->probe()->run($this->settings());
        $this->buckets->corruptWrites = false;
        $this->buckets->failDeletes   = true;
        $delete                       = $this->probe()->run($this->settings());

        self::assertSame([StorageProbeResult::STEP_WRITE, StorageProbeResult::REASON_OTHER], [$write->failedStep, $write->reason]);
        self::assertSame([StorageProbeResult::STEP_READ, StorageProbeResult::REASON_MISMATCH], [$read->failedStep, $read->reason]);
        self::assertSame(StorageProbeResult::STEP_DELETE, $delete->failedStep);
        foreach ($this->log as $line) {
            self::assertStringNotContainsString('not-a-real-secret', $line);
            self::assertStringNotContainsString('AKIAEXAMPLE', $line);
        }
        self::assertNotSame([], $this->log);
    }
}
