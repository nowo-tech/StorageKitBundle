<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Storage;

use AsyncAws\Core\Exception\Http\ClientException as AwsClientException;
use AsyncAws\Core\Exception\Http\NetworkException;
use League\Flysystem\Visibility;
use Nowo\StorageKitBundle\Storage\ErrorScrubber;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Storage\PrivateVisibilityConverter;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\StorageKitBundle\Storage\StorageProbeResult;
use Nowo\StorageKitBundle\Tests\Support\InMemoryBuckets;
use Nowo\StorageKitBundle\Tests\Support\InMemoryStorageConnectionFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function iterator_to_array;
use function str_repeat;

final class StorageBasicsTest extends TestCase
{
    public function testEveryObjectStaysPrivate(): void
    {
        $converter = new PrivateVisibilityConverter();

        self::assertSame('bucket-owner-full-control', $converter->visibilityToAcl(Visibility::PUBLIC));
        self::assertSame(Visibility::PRIVATE, $converter->aclToVisibility([['Permission' => 'READ']]));
        self::assertSame(Visibility::PRIVATE, $converter->defaultForDirectories());
    }

    public function testConnectionsAreMemoisedUntilReset(): void
    {
        $buckets  = new InMemoryBuckets();
        $storage  = new FileStorage(new InMemoryStorageConnectionFactory($buckets));
        $settings = new S3Settings('files', 'key', 'secret');

        self::assertSame($storage->connection($settings), $storage->connection($settings));
        $storage->reset();
        $storage->connection($settings);
        self::assertSame(['files', 'files'], $buckets->connected);
    }

    public function testListingsAreBounded(): void
    {
        $connection = (new InMemoryStorageConnectionFactory(new InMemoryBuckets()))->connect(new S3Settings('files', 'key', 'secret', prefix: 'p'));
        foreach (['a', 'b', 'c/d'] as $key) {
            $connection->write($key, 'x', 'text/plain');
        }

        self::assertSame(['a', 'b'], iterator_to_array($connection->keys(2), false));
        self::assertCount(3, iterator_to_array($connection->keys(10), false));
    }

    public function testFailuresBecomeGenericReasons(): void
    {
        $http  = new MockHttpClient(new MockResponse('denied', ['http_code' => 403]));
        $error = new ClientException($http->request('GET', 'https://s3.example.com/x'));

        self::assertSame([StorageProbeResult::REASON_HTTP, 403], ErrorScrubber::reason(new RuntimeException('wrapped', 0, $error)));
        self::assertSame([StorageProbeResult::REASON_UNREACHABLE, null], ErrorScrubber::reason(new TransportException('down')));
        self::assertSame([StorageProbeResult::REASON_OTHER, null], ErrorScrubber::reason(new RuntimeException('x')));

        $aws = new AwsClientException((new MockHttpClient(new MockResponse('<Error/>', ['http_code' => 404])))->request('GET', 'https://s3.example.com/y'));
        self::assertSame([StorageProbeResult::REASON_HTTP, 404], ErrorScrubber::reason($aws));
        self::assertSame([StorageProbeResult::REASON_UNREACHABLE, null], ErrorScrubber::reason(new NetworkException('down')));
    }

    public function testAnUnreadableAnswerIsUnreachable(): void
    {
        $http  = new MockHttpClient(new MockResponse('', ['error' => 'reset']));
        $error = new class($http->request('GET', 'https://s3.example.com/z')) extends RuntimeException implements HttpExceptionInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
                parent::__construct('x');
            }

            public function getResponse(): ResponseInterface
            {
                return $this->response;
            }
        };

        self::assertSame([StorageProbeResult::REASON_UNREACHABLE, null], ErrorScrubber::reason($error));
    }

    public function testMessagesLoseCredentialsAndSignatures(): void
    {
        $settings = new S3Settings('files', 'AKIAEXAMPLE', 'not-a-real-secret');
        $other    = new S3Settings('files', 'AKIAOTHER', 'other-secret');
        $message  = ErrorScrubber::message(new RuntimeException('AKIAEXAMPLE / not-a-real-secret / AKIAOTHER at https://x?X-Amz-Signature=abc&X-Amz-Credential=AKIA%2F1 ' . str_repeat('y', 600)), $settings, $other);

        self::assertStringNotContainsString('AKIAEXAMPLE', $message);
        self::assertStringNotContainsString('not-a-real-secret', $message);
        self::assertStringNotContainsString('AKIAOTHER', $message);
        self::assertStringNotContainsString('abc', $message);
        self::assertStringContainsString('X-Amz-Signature=***', $message);
        self::assertSame(ErrorScrubber::MAX, mb_strlen($message));
    }

    public function testProbeResults(): void
    {
        self::assertTrue((new StorageProbeResult())->ok());
        self::assertNull((new StorageProbeResult())->statusClass());
        self::assertSame('4xx', (new StorageProbeResult('write', 'http', 403))->statusClass());
        self::assertFalse((new StorageProbeResult('write', 'http', 403))->ok());
    }
}
