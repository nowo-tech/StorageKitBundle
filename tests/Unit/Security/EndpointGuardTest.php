<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Security;

use Nowo\StorageKitBundle\Security\EndpointGuard;
use Nowo\StorageKitBundle\Storage\S3Settings;
use Nowo\StorageKitBundle\Tests\Support\FakeDns;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

use const PHP_BINARY;

/**
 * SEC-STORAGE-SSRF: endpoints of page-edited buckets — every IP spelling, DNS answers, metadata, allow-list.
 */
final class EndpointGuardTest extends TestCase
{
    /**
     * @param list<string> $allowed
     */
    private function guard(array $allowed = [], bool $private = false, bool $dns = true): EndpointGuard
    {
        return new EndpointGuard($allowed, $private, $dns, new FakeDns([
            's3.example.com'     => ['93.184.216.34', '2606:2800:220:1::248'],
            'rebind.example.com' => ['93.184.216.35', '10.0.0.5'],
            'meta.example.com'   => ['169.254.169.254'],
            'mapped.example.com' => ['::ffff:127.0.0.1'],
            'azure.example.com'  => ['168.63.129.16'],
        ], ['legacy.example.com' => ['93.184.216.36'], 'legacy-private.example.com' => ['192.168.1.2']]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1:9000'];
        yield 'short loopback' => ['http://127.1'];
        yield 'decimal loopback' => ['http://2130706433'];
        yield 'hex loopback' => ['http://0x7f000001'];
        yield 'octal loopback' => ['http://0177.0.0.1'];
        yield 'ipv6 loopback' => ['http://[::1]'];
        yield 'ipv4-mapped ipv6' => ['http://[::ffff:127.0.0.1]'];
        yield 'private' => ['http://10.1.2.3'];
        yield 'link-local metadata' => ['http://169.254.169.254'];
        yield 'metadata decimal' => ['http://2852039166'];
        yield 'alibaba metadata' => ['http://100.100.100.200'];
        yield 'metadata name' => ['http://metadata.google.internal'];
        yield 'localhost' => ['http://localhost:9000'];
        yield 'dot local' => ['http://minio.local'];
        yield 'single label' => ['http://rustfs:9000'];
        yield 'dns private answer' => ['https://rebind.example.com'];
        yield 'dns metadata answer' => ['https://meta.example.com'];
        yield 'dns mapped answer' => ['https://mapped.example.com'];
        yield 'gethostbyname private' => ['https://legacy-private.example.com'];
        yield 'azure wire server' => ['http://168.63.129.16'];
        yield 'dns azure answer' => ['https://azure.example.com'];
    }

    #[DataProvider('refused')]
    public function testPrivateLocalAndMetadataEndpointsAreRefused(string $endpoint): void
    {
        self::assertSame(EndpointGuard::NOT_ALLOWED, $this->guard()->check($endpoint), $endpoint);
    }

    public function testPublicEndpointsAndAwsPass(): void
    {
        $guard = $this->guard();

        self::assertNull($guard->check(null));
        self::assertNull($guard->check(''));
        self::assertNull($guard->check('https://s3.example.com'));
        self::assertNull($guard->check('https://legacy.example.com'));
        self::assertNull($guard->check('https://93.184.216.34:443'));
        self::assertSame(EndpointGuard::UNRESOLVABLE, $guard->check('https://nowhere.example.com'));
        self::assertSame(EndpointGuard::INVALID, $guard->check('https://s3.example.com/path'));
        self::assertSame(EndpointGuard::INVALID, $guard->check('gopher://s3.example.com'));
        self::assertNull($this->guard(dns: false)->check('https://rebind.example.com'));
    }

    public function testAllowListedAndPrivateModesKeepMetadataClosed(): void
    {
        $guard = $this->guard(['RustFS', 'minio.local.']);

        self::assertNull($guard->check('http://rustfs:9000'));
        self::assertNull($guard->check('http://minio.local'));
        self::assertTrue($guard->isAllowListed('[RUSTFS]'));
        self::assertSame(EndpointGuard::NOT_ALLOWED, $this->guard(['169.254.169.254'])->check('http://169.254.169.254'));
        self::assertNull($this->guard(private: true)->check('http://10.0.0.1'));
        self::assertSame(EndpointGuard::NOT_ALLOWED, $this->guard(private: true)->check('http://169.254.169.254'));
    }

    public function testOnlyRestrictedSettingsAreChecked(): void
    {
        $settings = new S3Settings('bucket', 'key', 'secret', endpoint: 'http://127.0.0.1:9000');

        self::assertNull($this->guard()->checkSettings($settings));
        self::assertSame(EndpointGuard::NOT_ALLOWED, $this->guard()->checkSettings($settings->withRestricted(true)));
    }

    public function testRestrictedRequestsGoThroughTheConnectTimeFilter(): void
    {
        $mock     = new MockHttpClient(static fn (): MockResponse => new MockResponse('ok'));
        $settings = new S3Settings('bucket', 'key', 'secret', endpoint: 'https://s3.example.com', restricted: true);
        $client   = $this->guard()->httpClient($mock, $settings);

        self::assertInstanceOf(NoPrivateNetworkHttpClient::class, $client);
        try {
            $client->request('GET', 'https://s3.example.com/bucket/x', ['resolve' => ['s3.example.com' => '10.0.0.5']])->getContent();
            self::fail('a private address was reached');
        } catch (TransportException $e) {
            self::assertStringContainsString('blocked', $e->getMessage());
        }
        self::assertSame('ok', $client->request('GET', 'https://s3.example.com/bucket/x', ['resolve' => ['s3.example.com' => '93.184.216.34']])->getContent());
        self::assertSame('ok', $this->guard()->httpClient($mock, $settings->withRestricted(false))->request('GET', 'https://s3.example.com/x', ['resolve' => ['s3.example.com' => '10.0.0.5']])->getContent());
        self::assertInstanceOf(NoPrivateNetworkHttpClient::class, $this->guard()->httpClient(null, $settings));
    }

    public function testAllowListedHostsOnlyKeepTheMetadataRangesClosed(): void
    {
        $settings = new S3Settings('bucket', 'key', 'secret', endpoint: 'http://minio:9000', restricted: true);
        $mock     = new MockHttpClient(static fn (): MockResponse => new MockResponse('ok'));
        $client   = $this->guard(['minio'])->httpClient($mock, $settings);

        self::assertSame('ok', $client->request('GET', 'http://minio:9000/bucket/x', ['resolve' => ['minio' => '172.18.0.4']])->getContent());
        self::assertSame('ok', $this->guard(private: true)->httpClient($mock, $settings)->request('GET', 'http://minio:9000/bucket/x', ['resolve' => ['minio' => '10.0.0.9']])->getContent());
        $this->expectException(TransportException::class);
        $client->request('GET', 'http://minio:9000/bucket/x', ['resolve' => ['minio' => '169.254.169.254']])->getContent();
    }

    public function testTheAzureWireServerIsRefusedEvenWhenPrivateHostsAreAllowed(): void
    {
        self::assertSame(EndpointGuard::NOT_ALLOWED, $this->guard(private: true)->check('http://168.63.129.16'));
        self::assertTrue(EndpointGuard::isMetadataIp('168.63.129.16'));
        self::assertFalse(EndpointGuard::isMetadataIp('93.184.216.34'));

        $settings = new S3Settings('bucket', 'key', 'secret', endpoint: 'https://s3.example.com', restricted: true);
        $client   = $this->guard()->httpClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('ok')), $settings);
        $this->expectException(TransportException::class);
        $client->request('GET', 'https://s3.example.com/bucket/x', ['resolve' => ['s3.example.com' => '168.63.129.16']])->getContent();
    }

    /**
     * Audit CJ M1: the timeout, the total limit and "no redirects" reach the request through the private-network
     * filter (it used to replace them with its defaults: 60 s idle, no total limit, 20 followed redirects).
     */
    public function testTheFilterKeepsTheTimeoutsAndFollowsNoRedirect(): void
    {
        $seen = [];
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = [$url, $options['timeout'], $options['max_duration'], $options['max_redirects']];

            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://s3.example.com/elsewhere']]);
        });
        $guard = new EndpointGuard([], false, false, new FakeDns([]), 1.5);
        foreach ([true, false] as $restricted) {
            $seen     = [];
            $settings = new S3Settings('bucket', 'key', 'secret', endpoint: 'https://s3.example.com', restricted: $restricted);
            $response = $guard->httpClient($mock, $settings)->request('GET', 'https://s3.example.com/bucket/x', ['resolve' => ['s3.example.com' => '93.184.216.34']]);

            self::assertSame(302, $response->getStatusCode(), 'the redirect is not followed');
            self::assertCount(1, $seen);
            self::assertSame(1.5, $seen[0][1]);
            self::assertSame(1.5 * EndpointGuard::DURATION_FACTOR, $seen[0][2]);
            self::assertSame(0, $seen[0][3]);
        }
    }

    /**
     * Audit CJ M1, end to end: a real server that answers too slowly is cut at the idle timeout, and its redirect is
     * returned, not followed.
     */
    public function testASlowOrRedirectingServerCannotHoldTheWorker(): void
    {
        $router = sys_get_temp_dir() . '/storage-kit-slow-' . getmypid() . '.php';
        file_put_contents($router, '<?php if ($_SERVER["REQUEST_URI"] === "/slow") { sleep(5); echo "late"; return; } if ($_SERVER["REQUEST_URI"] === "/redirect") { header("Location: /target", true, 302); return; } echo "target";');
        $port    = 18000 + getmypid() % 1000;
        $process = new Process([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router]);
        $process->setTimeout(30);
        $process->start();
        try {
            // The built-in server prints "Development Server (…) started" once it listens.
            $process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'started'));
            $settings = new S3Settings('bucket', 'key', 'secret', endpoint: 'http://127.0.0.1:' . $port, restricted: true);
            $client   = (new EndpointGuard([], true, false, new FakeDns([]), 0.5))->httpClient(null, $settings);

            $redirect = $client->request('GET', 'http://127.0.0.1:' . $port . '/redirect');
            self::assertSame(302, $redirect->getStatusCode());
            self::assertSame('', $redirect->getContent(false));

            $start = microtime(true);
            try {
                $client->request('GET', 'http://127.0.0.1:' . $port . '/slow')->getContent();
                self::fail('the slow answer was waited for');
            } catch (TransportExceptionInterface) {
                self::assertLessThan(3.0, microtime(true) - $start);
            }
        } finally {
            $process->stop(0);
            @unlink($router);
        }
    }
}
