<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Functional;

use Doctrine\DBAL\ParameterType;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Tests\Fixtures\UploadLimit;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageProcessor;
use Nowo\StorageKitBundle\Upload\UploadEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function array_keys;
use function file_put_contents;
use function is_array;
use function json_decode;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;

use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_PARTIAL;

/**
 * The upload endpoint (CSRF bound to the context, roles, rate limit, server-side sniffing, caps, server-generated
 * keys, nothing left behind on a refusal) and the download endpoint (context read check, sandboxed streaming or
 * pre-signed redirect).
 */
final class UploadDownloadTest extends KernelTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function post(StorageKitTestKernel $kernel, KernelBrowser $client, string $bytes, string $board = 'b1', ?string $token = null, string $name = 'photo.png', ?string $tokenBoard = null): array
    {
        $client->request('GET', '/storage/files/00000000-0000-4000-8000-000000000000/preview');
        $path = (string) tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $bytes);
        $client->request('POST', '/storage/upload/board/' . $board, [], ['file' => new UploadedFile($path, $name, 'image/png', null, true)], [
            'HTTP_X_CSRF_TOKEN' => $token ?? $this->token($kernel, $client, UploadEndpoint::tokenId('board', $tokenBoard ?? $board)),
        ]);
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        return is_array($data) ? $data : [];
    }

    public function testAnImageIsStoredUnderAServerKeyAndAnswersForTheEditor(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $client = $this->client($kernel, 'alice');
        $data   = $this->post($kernel, $client, Images::png(2000, 1000), name: '../../etc/[evil](x).png');

        self::assertSame(201, $client->getResponse()->getStatusCode());
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertTrue($data['image']);
        self::assertSame('storage-file:' . $data['uuid'], $data['uri']);
        self::assertSame('/storage/files/' . $data['uuid'] . '/preview', $data['url']);
        self::assertSame('/storage/files/' . $data['uuid'] . '/original', $data['original_url']);
        self::assertSame('[evil](x).png', $data['name']);
        self::assertSame('evil x', $data['alt']);
        self::assertSame('![evil x](storage-file:' . $data['uuid'] . ')', $data['markdown']);

        $file = $this->entityManager($kernel)->getRepository(StoredFile::class)->findOneBy(['uuid' => $data['uuid']]);
        self::assertInstanceOf(StoredFile::class, $file);
        self::assertMatchesRegularExpression('#^\d{4}/\d{2}/' . $data['uuid'] . '$#', $file->getStorageKey());
        self::assertSame(['alice', 'owner-b1', 'board', 'b1'], [$file->getUploadedBy(), $file->getOwner(), $file->getContextType(), $file->getContextId()]);
        self::assertNotNull($file->getOrphanedSince());
        $objects = array_keys($this->buckets($kernel)->objects('files', 'http://minio:9000'));
        self::assertSame(['app/' . $file->getStorageKey(), 'app/' . $file->getStorageKey() . '-preview', 'app/' . $file->getStorageKey() . '-thumb'], $objects);

        // Download: streamed, sandboxed, nosniff, attachment.
        $client->request('GET', $data['url']);
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        self::assertSame('attachment; filename=evil_x.png', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        $body = $client->getInternalResponse()->getContent();
        self::assertSame('image/png', ImageProcessor::sniff($body));
        self::assertInstanceOf(StreamedResponse::class, $response, 'streamed, never loaded whole in memory');
        self::assertSame((string) strlen($body), $response->headers->get('Content-Length'));

        // Who may not read the board gets a 404, like a missing file.
        foreach (['bob' => 200, 'guest' => 404] as $user => $status) {
            $other = $this->client($kernel, $user);
            $other->request('GET', $data['url']);
            self::assertSame($user === 'bob' ? 200 : 404, $other->getResponse()->getStatusCode(), $user);
        }
        $anonymous = $this->client($kernel, null);
        $anonymous->request('GET', $data['url']);
        self::assertSame(404, $anonymous->getResponse()->getStatusCode());
        $client->request('GET', '/storage/files/' . $data['uuid'] . '/huge');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function refusals(): iterable
    {
        yield 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'type', 422];
        yield 'html' => ['<!doctype html><script>alert(1)</script>', 'type', 422];
        yield 'polyglot' => ["\x89PNG\r\n\x1a\n<html><script>alert(1)</script>", 'pixels', 422];
        yield 'too large' => [str_repeat('a', 3000), 'size', 413];
    }

    #[DataProvider('refusals')]
    public function testRefusedFilesLeaveNothingBehind(string $bytes, string $reason, int $status): void
    {
        $kernel = $this->bootKernel(['upload' => ['max_bytes' => 2048]]);
        $this->profile($kernel);
        $client = $this->client($kernel, 'alice');
        $data   = $this->post($kernel, $client, $bytes);

        self::assertSame($status, $client->getResponse()->getStatusCode());
        self::assertSame($reason, $data['error']);
        self::assertNotSame('', $data['message']);
        self::assertSame([], $this->buckets($kernel)->objects('files', 'http://minio:9000'));
        self::assertSame(0, $this->entityManager($kernel)->getRepository(StoredFile::class)->count([]));
    }

    public function testTheTokenIsBoundToTheContext(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $client = $this->client($kernel, 'alice');

        self::assertSame('csrf', $this->post($kernel, $client, Images::png(), token: 'forged')['error']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame('csrf', $this->post($kernel, $client, Images::png(), token: '')['error']);
        self::assertSame('csrf', $this->post($kernel, $client, Images::png(), 'b1', tokenBoard: 'pub')['error'], 'a token of another context is refused');
    }

    public function testAccessContextAndStorageRefusals(): void
    {
        $kernel = $this->bootKernel(['limits' => ['upload_limit' => UploadLimit::class]]);
        $client = $this->client($kernel, 'alice');
        self::assertSame(['off', 409], [$this->post($kernel, $client, Images::png())['error'], $client->getResponse()->getStatusCode()]);

        $this->profile($kernel);
        self::assertSame(['context', 404], [$this->post($kernel, $client, Images::png(), 'b2')['error'], $client->getResponse()->getStatusCode()]);
        self::assertSame(['context', 404], [$this->post($kernel, $client, Images::png(), 'nope')['error'], $client->getResponse()->getStatusCode()]);
        $bob = $this->client($kernel, 'bob');
        self::assertSame(['limit', 403], [$this->post($kernel, $bob, Images::png(), 'b2')['error'], $bob->getResponse()->getStatusCode()]);
        $guest = $this->client($kernel, 'guest');
        self::assertSame(['access', 403], [$this->post($kernel, $guest, Images::png())['error'], $guest->getResponse()->getStatusCode()]);
        $anonymous = $this->client($kernel, null);
        self::assertSame(['access', 403], [$this->post($kernel, $anonymous, Images::png())['error'], $anonymous->getResponse()->getStatusCode()]);

        $this->buckets($kernel)->writesLeft = 1;
        self::assertSame(['storage', 503], [$this->post($kernel, $client, Images::png(2000, 1000))['error'], $client->getResponse()->getStatusCode()]);
        self::assertSame([], $this->buckets($kernel)->objects('files', 'http://minio:9000'), 'the objects already written are removed');
        $this->buckets($kernel)->writesLeft  = 0;
        $this->buckets($kernel)->failDeletes = true;
        self::assertSame('storage', $this->post($kernel, $client, Images::png())['error']);
        self::assertSame(0, $this->entityManager($kernel)->getRepository(StoredFile::class)->count([]));
    }

    public function testNoFileOrAnIncompleteUpload(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $client = $this->client($kernel, 'alice');
        $client->request('GET', '/storage/files/00000000-0000-4000-8000-000000000000/preview');
        $client->request('POST', '/storage/upload/board/b1', [], [], ['HTTP_X_CSRF_TOKEN' => $this->token($kernel, $client, UploadEndpoint::tokenId('board', 'b1'))]);
        self::assertSame(['empty', 422], [json_decode((string) $client->getResponse()->getContent(), true)['error'] ?? null, $client->getResponse()->getStatusCode()]);

        $path = (string) tempnam(sys_get_temp_dir(), 'upl');
        $client->request('POST', '/storage/upload/board/b1', [], ['file' => new UploadedFile($path, 'a.png', 'image/png', UPLOAD_ERR_INI_SIZE, true)], ['HTTP_X_CSRF_TOKEN' => $this->token($kernel, $client, UploadEndpoint::tokenId('board', 'b1'))]);
        self::assertSame(413, $client->getResponse()->getStatusCode());
        $client->request('POST', '/storage/upload/board/b1', [], ['file' => new UploadedFile($path, 'a.png', 'image/png', UPLOAD_ERR_PARTIAL, true)], ['HTTP_X_CSRF_TOKEN' => $this->token($kernel, $client, UploadEndpoint::tokenId('board', 'b1'))]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testUploadsAreRateLimitedPerUser(): void
    {
        $kernel = $this->bootKernel(['upload' => ['rate_limit' => 1, 'rate_interval' => '1 hour']]);
        $this->profile($kernel);
        $client = $this->client($kernel, 'alice');

        $this->post($kernel, $client, Images::png());
        self::assertSame(201, $client->getResponse()->getStatusCode());
        self::assertSame('rate', $this->post($kernel, $client, Images::png())['error']);
        self::assertSame(429, $client->getResponse()->getStatusCode());
    }

    public function testAScopeUploadGoesToTheScopeBucket(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $scope  = $this->profile($kernel, 'acme', 'acme-files');
        $client = $this->client($kernel, 'alice');
        $data   = $this->post($kernel, $client, Images::png(), 'acme');

        self::assertSame(201, $client->getResponse()->getStatusCode());
        self::assertCount(1, $this->buckets($kernel)->objects('acme-files', 'http://minio:9000'));
        self::assertSame([], $this->buckets($kernel)->objects('files', 'http://minio:9000'));

        // A scope bucket that is turned off turns the scope's uploads off (no fallback to the instance bucket).
        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE storage_kit_profile SET enabled = ? WHERE scope = 'acme'", [false], [ParameterType::BOOLEAN]);
        self::assertSame('off', $this->post($kernel, $client, Images::png(), 'acme')['error']);
        self::assertSame('acme', $scope->getScope());
        self::assertNotSame('', $data['uuid']);
    }

    public function testARedirectDeliveredConfigProfileSendsBrowsersToThePresignedUrl(): void
    {
        $kernel = $this->bootKernel([
            'profiles'         => ['main' => ['dsn' => 's3://test-key:test-secret-not-real@minio:9000/main?tls=0&path_style=1&public_endpoint=http://localhost:9124']],
            'instance_profile' => 'main',
            'download'         => ['url_ttl' => 120, 'disposition' => 'inline'],
        ]);
        $client = $this->client($kernel, 'alice');
        $data   = $this->post($kernel, $client, Images::png(), 'pub');
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $anonymous = $this->client($kernel, null);
        $anonymous->request('GET', $data['original_url']);
        $response = $anonymous->getResponse();
        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('https://main.storage.test/', (string) $response->headers->get('Location'));
        self::assertStringContainsString('disposition=inline%3B%20filename%3Dphoto.png', (string) $response->headers->get('Location'));
        self::assertStringContainsString('max-age=60', (string) $response->headers->get('Cache-Control'));
    }

    public function testAFileWhoseProfileIsNotUsableAnyMoreIs404(): void
    {
        $kernel  = $this->bootKernel();
        $profile = $this->profile($kernel);
        $client  = $this->client($kernel, 'alice');
        $data    = $this->post($kernel, $client, Images::png());
        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE storage_kit_profile SET region = 'BAD'");
        $this->entityManager($kernel)->clear();
        $client->request('GET', $data['url']);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNotNull($profile->getId());
    }

    public function testWithoutTheEndpointsThereIsNoRoute(): void
    {
        $kernel = $this->bootKernel(['upload' => ['enabled' => false]]);
        $client = $this->client($kernel, 'alice');
        $client->request('POST', '/storage/upload/board/b1');

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame(StorageProfile::STATE_ACTIVE, (new StorageProfile())->getState());
    }
}
