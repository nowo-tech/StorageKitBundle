<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Storage;

use InvalidArgumentException;
use Nowo\StorageKitBundle\Storage\S3Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function print_r;
use function var_export;

use const PHP_URL_SCHEME;

final class S3SettingsTest extends TestCase
{
    public function testADsnOfACompatibleService(): void
    {
        $settings = S3Settings::fromDsn('s3://KEY%2Fid:se%2Bcret@minio:9000/files/app/images?region=eu-west-1&path_style=1&tls=0&public_endpoint=http://localhost:9124');

        self::assertSame('files', $settings->bucket);
        self::assertSame('KEY/id', $settings->accessKey);
        self::assertSame('se+cret', $settings->secret);
        self::assertSame('eu-west-1', $settings->region);
        self::assertSame('http://minio:9000', $settings->endpoint);
        self::assertSame('http://localhost:9124', $settings->publicEndpoint);
        self::assertSame('app/images', $settings->prefix);
        self::assertTrue($settings->pathStyle);
        self::assertFalse($settings->restricted);
        self::assertSame('app/images/2026/10/x', $settings->key('2026/10/x'));
        self::assertSame('http://localhost:9124', $settings->browserOrigin());
    }

    public function testADsnOfAwsItself(): void
    {
        $settings = S3Settings::fromDsn('s3://key:secret@aws/my-bucket', true);

        self::assertNull($settings->endpoint);
        self::assertSame('us-east-1', $settings->region);
        self::assertSame('', $settings->prefix);
        self::assertTrue($settings->restricted);
        self::assertFalse($settings->usesPathStyle());
        self::assertSame('x', $settings->key('x'));
        self::assertSame('https://my-bucket.s3.us-east-1.amazonaws.com', $settings->browserOrigin());
        self::assertSame('https', parse_url((string) S3Settings::fromDsn('s3://key:secret@s3.example.com/b-1')->endpoint, PHP_URL_SCHEME));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badDsns(): iterable
    {
        yield 'not s3' => ['https://key:secret@host/bucket'];
        yield 'no credentials' => ['s3://host/bucket'];
        yield 'no bucket' => ['s3://key:secret@host'];
        yield 'garbage' => ['s3://'];
        yield 'region host injection' => ['s3://key:secret@aws/bucket?region=x.evil.com%2F'];
        yield 'region with a dot' => ['s3://key:secret@aws/bucket?region=eu.west'];
        yield 'public endpoint with a path' => ['s3://key:secret@aws/bucket?public_endpoint=https://cdn.example.com/x'];
    }

    #[DataProvider('badDsns')]
    public function testBadDsnsAreRefusedWithoutTheSecret(string $dsn): void
    {
        try {
            S3Settings::fromDsn($dsn);
            self::fail('accepted');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string|null, string|null, string}>
     */
    public static function badSettings(): iterable
    {
        yield 'bucket' => ['Bad_Bucket', 'key', 'secret', 'us-east-1', null, ''];
        yield 'bucket dots' => ['a..b', 'key', 'secret', 'us-east-1', null, ''];
        yield 'empty key' => ['bucket', '', 'secret', 'us-east-1', null, ''];
        yield 'key with space' => ['bucket', 'a b', 'secret', 'us-east-1', null, ''];
        yield 'empty secret' => ['bucket', 'key', '', 'us-east-1', null, ''];
        yield 'secret control' => ['bucket', 'key', "a\nb", 'us-east-1', null, ''];
        yield 'region' => ['bucket', 'key', 'secret', 'US-EAST-1', null, ''];
        yield 'long region' => ['bucket', 'key', 'secret', str_repeat('a', 33), null, ''];
        yield 'endpoint path' => ['bucket', 'key', 'secret', 'us-east-1', 'https://s3.example.com/path', ''];
        yield 'endpoint query' => ['bucket', 'key', 'secret', 'us-east-1', 'https://s3.example.com?x=1', ''];
        yield 'endpoint user' => ['bucket', 'key', 'secret', 'us-east-1', 'https://u:p@s3.example.com', ''];
        yield 'endpoint fragment' => ['bucket', 'key', 'secret', 'us-east-1', 'https://s3.example.com#x', ''];
        yield 'endpoint scheme' => ['bucket', 'key', 'secret', 'us-east-1', 'ftp://s3.example.com', ''];
        yield 'endpoint csp injection' => ['bucket', 'key', 'secret', 'us-east-1', 'https://s3.example.com;script-src', ''];
        yield 'endpoint no host' => ['bucket', 'key', 'secret', 'us-east-1', 'https://', ''];
        yield 'endpoint bad host' => ['bucket', 'key', 'secret', 'us-east-1', 'https://-bad-.example', ''];
        yield 'endpoint bad ipv6' => ['bucket', 'key', 'secret', 'us-east-1', 'https://[zz::1]', ''];
        yield 'prefix dotdot' => ['bucket', 'key', 'secret', 'us-east-1', null, 'a/../b'];
        yield 'prefix dot' => ['bucket', 'key', 'secret', 'us-east-1', null, './b'];
        yield 'prefix chars' => ['bucket', 'key', 'secret', 'us-east-1', null, 'a b'];
        yield 'prefix double slash' => ['bucket', 'key', 'secret', 'us-east-1', null, 'a//b'];
    }

    #[DataProvider('badSettings')]
    public function testMalformedOptionsAreRefused(string $bucket, string $key, string $secret, string $region, ?string $endpoint, string $prefix): void
    {
        $this->expectException(InvalidArgumentException::class);

        new S3Settings($bucket, $key, $secret, $region, $endpoint, null, $prefix);
    }

    public function testEndpointsAreNormalised(): void
    {
        self::assertNull(S3Settings::endpoint(null));
        self::assertNull(S3Settings::endpoint('  '));
        self::assertSame('https://s3.example.com', S3Settings::endpoint(' HTTPS://S3.Example.com/ '));
        self::assertSame('http://10.0.0.1:9000', S3Settings::endpoint('http://10.0.0.1:9000'));
        self::assertSame('http://[::1]:9000', S3Settings::endpoint('http://[::1]:9000'));
        self::assertSame('https://xn--bcher-kva.example', S3Settings::endpoint('https://bücher.example'));
        self::assertSame('us-east-1', S3Settings::region(' '));
    }

    public function testTheBrowserOriginFollowsTheClientAddressing(): void
    {
        self::assertSame('https://bucket.s3.example.com', (new S3Settings('bucket', 'k', 's', endpoint: 'https://s3.example.com'))->browserOrigin());
        self::assertSame('https://s3.example.com', (new S3Settings('bucket', 'k', 's', endpoint: 'https://s3.example.com', pathStyle: true))->browserOrigin());
        self::assertSame('https://s3.example.com', (new S3Settings('my.bucket', 'k', 's', endpoint: 'https://s3.example.com'))->browserOrigin());
        self::assertSame('http://127.0.0.1:9000', (new S3Settings('bucket', 'k', 's', endpoint: 'http://127.0.0.1:9000'))->browserOrigin());
        self::assertSame('https://s3.example.com', (new S3Settings('bucket', 'k', 's', endpoint: 'https://s3.example.com', restricted: true))->browserOrigin());
        self::assertSame('https://cdn.example.com:8443', (new S3Settings('bucket', 'k', 's', publicEndpoint: 'https://cdn.example.com:8443', pathStyle: true))->browserOrigin());
    }

    public function testRestrictedSettingsWithAnEndpointUsePathStyle(): void
    {
        $settings = new S3Settings('bucket', 'k', 's', endpoint: 'https://s3.example.com');

        self::assertFalse($settings->usesPathStyle());
        self::assertTrue($settings->withRestricted(true)->usesPathStyle());
        self::assertTrue($settings->withRestricted(true)->restricted);
        self::assertFalse($settings->withRestricted(true)->withRestricted(false)->restricted);
    }

    public function testTheSecretNeverShows(): void
    {
        $settings = new S3Settings('bucket', 'AKIAEXAMPLE', 'not-a-real-secret');

        self::assertStringNotContainsString('not-a-real-secret', print_r($settings, true));
        self::assertStringNotContainsString('AKIAEXAMPLE', print_r($settings, true));
        self::assertStringNotContainsString('not-a-real-secret', $settings->fingerprint());
        self::assertNotSame($settings->fingerprint(), (new S3Settings('bucket', 'AKIAEXAMPLE', 'other-secret'))->fingerprint());
        self::assertNotSame($settings->fingerprint(), $settings->withRestricted(true)->fingerprint());
        self::assertStringContainsString('bucket', var_export($settings->__debugInfo(), true));
    }
}
