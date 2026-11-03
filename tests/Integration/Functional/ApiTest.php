<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Functional;

use Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface;
use Nowo\StorageKitBundle\Download\StoredImage;
use Nowo\StorageKitBundle\Download\StoredImageResolver;
use Nowo\StorageKitBundle\Http\StorageCspSources;
use Nowo\StorageKitBundle\Integration\HttpHardening\StorageCspSourceProvider;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Twig\StorageKitTwigExtension;
use Nowo\StorageKitBundle\Upload\ImageUploader;
use Nowo\StorageKitBundle\Upload\UploadEndpoint;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

/**
 * The PHP / Twig API for editors and renderers (upload configuration, MilkdownEditor options, file URLs, visible
 * images) and the CSP sources.
 */
final class ApiTest extends KernelTestCase
{
    /**
     * Signs `$user` in for the services (a main request behind the firewall, with a session for CSRF tokens).
     *
     * @param list<string> $roles
     */
    private function signIn(StorageKitTestKernel $kernel, ?string $user, array $roles = ['ROLE_USER']): void
    {
        $request = Request::create('/');
        $request->attributes->set('_firewall_context', 'security.firewall.map.context.main');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = $this->service($kernel, 'request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        while ($stack->pop() instanceof Request) {
        }
        $stack->push($request);
        $tokens = $this->service($kernel, 'security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken($user === null ? null : new UsernamePasswordToken(new InMemoryUser($user, null, $roles), 'main', $roles));
    }

    public function testTheUploadConfigurationOfAContext(): void
    {
        $kernel   = $this->bootKernel();
        $endpoint = $this->service($kernel, UploadEndpoint::class);

        $this->signIn($kernel, null);
        self::assertSame(['state' => 'none'], $endpoint->config('board', 'b1'));
        $this->signIn($kernel, 'guest', ['ROLE_GUEST']);
        self::assertSame(['state' => 'none'], $endpoint->config('board', 'b1'));
        $this->signIn($kernel, 'alice');
        self::assertSame(['state' => 'none'], $endpoint->config('board', 'b2'));
        self::assertSame(['state' => 'none'], $endpoint->config('Board', 'b1'));
        self::assertSame(['state' => 'none'], $endpoint->config('nope', 'b1'));
        self::assertSame(['state' => 'off', 'setup_url' => null], $endpoint->config('board', 'b1'));
        self::assertSame([], $endpoint->milkdownOptions('board', 'b1'));
        $this->signIn($kernel, 'admin', ['ROLE_ADMIN', 'ROLE_USER']);
        self::assertSame(['state' => 'off', 'setup_url' => '/admin/storage'], $endpoint->config('board', 'b1'));
        $this->signIn($kernel, 'scopeadmin');
        self::assertSame(['state' => 'off', 'setup_url' => '/storage/scopes/acme'], $endpoint->config('board', 'acme'));

        $this->profile($kernel);
        $this->service($kernel, StorageProfiles::class)->reset();
        $this->signIn($kernel, 'alice');
        $on = $endpoint->config('board', 'b1');
        self::assertSame('on', $on['state']);
        self::assertSame('/storage/upload/board/b1', $on['url'] ?? null);
        self::assertSame('X-CSRF-Token', $on['header'] ?? null);
        self::assertSame('file', $on['field'] ?? null);
        self::assertSame(['image/png', 'image/jpeg', 'image/gif', 'image/webp'], $on['accept'] ?? null);
        self::assertNotSame('', $on['token'] ?? '');
        $milkdown = $endpoint->milkdownOptions('board', 'b1');
        self::assertSame(['upload_url', 'upload_csrf_token', 'upload_max_size', 'upload_accept'], array_keys($milkdown));
        self::assertSame(10 * 1024 * 1024, $milkdown['upload_max_size'] ?? null);
    }

    public function testWithoutThePagesThereIsNoSetupLink(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['enabled' => false]]);
        $this->signIn($kernel, 'admin', ['ROLE_ADMIN', 'ROLE_USER']);

        self::assertSame(['state' => 'off', 'setup_url' => null], $this->service($kernel, UploadEndpoint::class)->config('board', 'b1'));
    }

    public function testTwigFunctionsAndTheImageResolver(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $file = $this->service($kernel, ImageUploader::class)->upload(new InMemoryUser('alice', null), 'board', 'b1', Images::png(2000, 1000), 'photo.png');
        $twig = $this->service($kernel, 'twig');
        self::assertInstanceOf(Environment::class, $twig);
        $text = '![a](storage-file:' . $file->getUuid() . ')';

        $this->signIn($kernel, 'alice');
        $html = $twig->createTemplate(
            "{{ nowo_storage_file_url(uuid) }}|{{ nowo_storage_file_url(uuid, 'original') }}|{{ nowo_storage_file_url(uuid, 'huge') }}|"
            . '{% for id, image in nowo_storage_images(text) %}{{ image.src }} {{ image.width }}x{{ image.height }} {{ image.alt }}{% endfor %}|{{ nowo_storage_images(null)|length }}|'
            . "{{ nowo_storage_upload('board', 'b1').state }}|{{ nowo_storage_milkdown_upload('board', 'b1').upload_url }}",
        )->render(['uuid' => $file->getUuid(), 'text' => $text]);
        self::assertSame(
            '/storage/files/' . $file->getUuid() . '/preview|/storage/files/' . $file->getUuid() . '/original|/storage/files/' . $file->getUuid() . '/preview|'
            . '/storage/files/' . $file->getUuid() . '/preview 1600x800 photo.png|0|on|/storage/upload/board/b1',
            $html,
        );

        $this->signIn($kernel, 'guest', ['ROLE_GUEST']);
        self::assertSame([], $this->service($kernel, StoredImageResolver::class)->resolveText($text, null));
        $images = $this->service($kernel, StoredImageResolver::class)->resolve([$file->getUuid()], new InMemoryUser('bob', null));
        self::assertInstanceOf(StoredImage::class, $images[$file->getUuid()]);
        self::assertSame('/storage/files/' . $file->getUuid() . '/original', $images[$file->getUuid()]->original);
        self::assertInstanceOf(StorageKitTwigExtension::class, $this->service($kernel, StorageKitTwigExtension::class));
    }

    public function testTheCspListsOnlyRedirectDeliveredConfigBuckets(): void
    {
        $kernel = $this->bootKernel([
            'profiles' => [
                'main'    => ['dsn' => 's3://k:test-secret-not-real@aws/main-bucket?region=eu-west-1'],
                'cdn'     => ['dsn' => 's3://k:s@minio:9000/cdn?tls=0&path_style=1&public_endpoint=https://cdn.example.com'],
                'proxied' => ['bucket' => 'p', 'access_key' => 'k', 'secret' => 's', 'endpoint' => 'https://hidden.example.com', 'delivery' => 'proxy'],
                'broken'  => ['dsn' => 's3://k:s@aws/b?region=bad.region'],
            ],
        ]);
        $this->profile($kernel, 'acme', 'tenant', endpoint: 'https://tenant.example.com');
        $sources = $this->service($kernel, StorageCspSources::class);

        self::assertSame(['https://main-bucket.s3.eu-west-1.amazonaws.com', 'https://cdn.example.com'], $sources->imgSrc());
        self::assertSame(['img-src' => ['https://main-bucket.s3.eu-west-1.amazonaws.com', 'https://cdn.example.com']], (new StorageCspSourceProvider($sources))->getSources(new Request()));
        self::assertTrue($this->container($kernel)->has(StorageCspSourceProvider::class) || interface_exists(CspSourceProviderInterface::class));

        $empty = $this->bootKernel();
        self::assertSame([], (new StorageCspSourceProvider($this->service($empty, StorageCspSources::class)))->getSources(new Request()));
    }
}
