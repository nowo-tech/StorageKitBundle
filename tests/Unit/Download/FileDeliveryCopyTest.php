<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Download;

use ArrayObject;
use Generator;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Nowo\StorageKitBundle\Download\FileDelivery;
use Nowo\StorageKitBundle\Security\EndpointGuard;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\StorageKitBundle\Storage\S3StorageConnectionFactory;
use Nowo\StorageKitBundle\Storage\StorageConnection;
use Nowo\StorageKitBundle\Tests\Support\FakeDns;
use Nowo\StorageKitBundle\Tests\Support\FakePresigner;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function implode;
use function iterator_to_array;
use function str_repeat;
use function strlen;

/**
 * Audit CJ L2 / L2b: a proxied download asks the bucket for the stored size only (`Range`), sends the body as it
 * arrives and never more than the stored size.
 */
final class FileDeliveryCopyTest extends TestCase
{
    public function testTheStoredSizeIsSentChunkByChunk(): void
    {
        $chunks = [];
        self::assertSame(25, FileDelivery::copy(['aaaaaaaaaa', 'bbbbbbbbbb', 'ccccc'], 25, static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }));
        self::assertSame(['aaaaaaaaaa', 'bbbbbbbbbb', 'ccccc'], $chunks);
        self::assertSame(3, FileDelivery::copy(['abc'], 10, static function (): void {
        }), 'a shorter object stops at its end');
    }

    public function testMoreThanTheStoredSizeIsCutAndTheRestNotRead(): void
    {
        $read   = new ArrayObject();
        $chunks = (static function () use ($read): Generator {
            for ($i = 0; $i < 100; ++$i) {
                $read->append($i);
                yield str_repeat('x', 30);
            }
        })();
        $sent = '';
        try {
            FileDelivery::copy($chunks, 40, static function (string $chunk) use (&$sent): void {
                $sent .= $chunk;
            });
            self::fail('a larger object was sent');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('larger than the size recorded', $e->getMessage());
        }
        self::assertSame(40, strlen($sent));
        self::assertCount(2, $read, 'nothing past the stored size is pulled from the bucket');
    }

    public function testTheBucketIsAskedForTheStoredSizeOnly(): void
    {
        $requests = [];
        $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options['normalized_headers']['range'] ?? []];
            if (($options['normalized_headers']['range'] ?? []) === ['Range: bytes=0-4']) {
                return new MockResponse('hello', ['http_code' => 206]);
            }

            // A hostile bucket ignores the range and streams a much larger body.
            return new MockResponse((static function (): Generator {
                for ($i = 0; $i < 1000; ++$i) {
                    yield str_repeat('y', 1000);
                }
            })(), ['http_code' => 200]);
        });
        $settings   = new S3Settings('files', 'test-key', 'test-secret-not-real', endpoint: 'https://93.184.216.34', prefix: 'app', restricted: true);
        $connection = (new S3StorageConnectionFactory(new EndpointGuard([], false, false, new FakeDns()), $http))->connect($settings);

        $sent = '';
        try {
            FileDelivery::copy($connection->readRange('2026/11/x', 2500), 2500, static function (string $chunk) use (&$sent): void {
                $sent .= $chunk;
            });
            self::fail('a larger object was sent');
        } catch (RuntimeException) {
        }
        self::assertSame(2500, strlen($sent));
        self::assertCount(1, $requests);
        self::assertSame(['GET', 'https://93.184.216.34/files/app/2026/11/x'], [$requests[0][0], $requests[0][1]]);
        self::assertSame(['Range: bytes=0-2499'], $requests[0][2]);
        self::assertSame([], iterator_to_array($connection->readRange('2026/11/x', 0)), 'an empty object is not requested');
        self::assertCount(1, $requests);
        self::assertSame('hello', implode('', iterator_to_array($connection->readRange('2026/11/y', 5), false)), 'a bucket that honours the range');
    }

    public function testWithoutAnS3ClientTheObjectStreamIsReadUpToOneBytePastTheSize(): void
    {
        $filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $filesystem->write('k', str_repeat('z', StorageConnection::CHUNK + 100));
        $settings   = new S3Settings('files', 'test-key', 'test-secret-not-real');
        $connection = new StorageConnection($filesystem, new FakePresigner($settings), $settings);

        $chunks = iterator_to_array($connection->readRange('k', StorageConnection::CHUNK + 10), false);
        self::assertSame([StorageConnection::CHUNK, 11], [strlen($chunks[0]), strlen($chunks[1])]);
    }
}
