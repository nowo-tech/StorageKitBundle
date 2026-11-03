<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Storage;

use DateTimeImmutable;
use Nowo\StorageKitBundle\Security\EndpointGuard;
use Nowo\StorageKitBundle\Storage\PrivateVisibilityConverter;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\StorageKitBundle\Storage\S3StorageConnectionFactory;
use Nowo\StorageKitBundle\Tests\Support\FakeDns;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function parse_str;
use function parse_url;

/**
 * The async-aws connection: requests go to the server-side endpoint (path style for restricted profiles), objects are
 * written with the private ACL, pre-signed URLs use the browser-facing endpoint and carry the response overrides.
 */
final class S3StorageConnectionFactoryTest extends TestCase
{
    public function testWritesReadsAndSignsThroughTheConfiguredEndpoints(): void
    {
        /** @var list<array{string, string, array<string, mixed>}> $requests */
        $requests = [];
        $http     = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            return match ($method) {
                'GET'   => new MockResponse('hello', ['response_headers' => ['content-type' => 'text/plain']]),
                'HEAD'  => new MockResponse('', ['http_code' => 200]),
                default => new MockResponse('', ['http_code' => $method === 'DELETE' ? 204 : 200]),
            };
        });
        $settings   = new S3Settings('files', 'test-key', 'test-secret-not-real', 'eu-west-1', 'https://93.184.216.34', 'https://cdn.example.com', 'app', restricted: true);
        $guard      = new EndpointGuard([], false, false, new FakeDns());
        $connection = (new S3StorageConnectionFactory($guard, $http))->connect($settings);

        $connection->write('2026/10/x', 'hello', 'text/plain');
        self::assertSame('hello', $connection->read('2026/10/x'));
        self::assertTrue($connection->exists('2026/10/x'));
        $connection->delete('2026/10/x');
        self::assertSame($settings, $connection->settings());

        [$method, $url, $options] = $requests[0];
        self::assertSame('PUT', $method);
        self::assertSame('https://93.184.216.34/files/app/2026/10/x', $url);
        self::assertContains('x-amz-acl: ' . PrivateVisibilityConverter::ACL, $options['headers'] ?? $options['normalized_headers']['x-amz-acl'] ?? []);

        $signed = $connection->presign('2026/10/x', 'image/png', 'attachment; filename="a.png"', new DateTimeImmutable('+5 minutes'));
        $parts  = (array) parse_url($signed);
        self::assertSame('cdn.example.com', $parts['host'] ?? null);
        parse_str((string) ($parts['query'] ?? ''), $query);
        self::assertSame('image/png', $query['response-content-type'] ?? null);
        self::assertSame('attachment; filename="a.png"', $query['response-content-disposition'] ?? null);
        self::assertArrayHasKey('X-Amz-Signature', $query);
        self::assertStringNotContainsString('test-secret-not-real', $signed);
    }

    public function testWithoutAPublicEndpointTheServerSideOneIsSigned(): void
    {
        $settings = new S3Settings('files', 'test-key', 'test-secret-not-real', endpoint: 'http://minio:9000', pathStyle: true);
        $signed   = (new S3StorageConnectionFactory(new EndpointGuard()))->connect($settings)->presign('k', 'image/png', 'inline', new DateTimeImmutable('+1 minute'));

        self::assertStringStartsWith('http://minio:9000/files/k?', $signed);
    }
}
