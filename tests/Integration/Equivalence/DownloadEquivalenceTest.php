<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Equivalence;

use Nowo\StorageKitBundle\Context\FileContexts;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Download\FileDelivery;
use Nowo\StorageKitBundle\Download\FileDeliveryInterface;
use Nowo\StorageKitBundle\Download\FileDeliveryResponder;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Nowo\StorageKitBundle\Security\AccessGuard;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\HostBucketProfiles;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Tests\Support\Legacy\FileDeliveryV140;
use Nowo\StorageKitBundle\Tests\Support\Legacy\FileDownloadControllerV140;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function ob_get_clean;
use function ob_start;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function time;

/**
 * 1.5 equivalence of the download path (REQ-ARCH-001 rule 3): the new port ({@see FileDeliveryInterface} +
 * {@see FileDeliveryResponder}, what the 1.5 controller calls) and the deprecated {@see FileDelivery::respond()} answer
 * exactly what the v1.4.0 code answers ({@see FileDeliveryV140} and {@see FileDownloadControllerV140}, literal copies):
 * same status, same headers (the `Date` header aside), same bytes — through the HTTP kernel (both controllers, same
 * viewer, same session) and at the service level — for streamed and redirect-delivered buckets, database and
 * configuration profiles, every variant, both dispositions, short and long URL lifetimes, hostile and empty names,
 * a stored type outside the image allowlist, unreadable contexts and buckets that are not connectable any more.
 *
 * Mutation-checked: changing a header, the max age, the URL lifetime, the stream size or the policy headers makes it
 * fail. (Dropping `setPrivate()` alone does not: HttpFoundation already adds `private` to a bare `max-age`.)
 */
final class DownloadEquivalenceTest extends KernelTestCase
{
    /** File names of the cases: plain, path traversal + Markdown, Unicode only (anonymous fallback), dots only. */
    private const NAMES = ['photo.png', '../../etc/[evil](x).png', 'Ünïcødé façade.jpg', '...'];

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function setups(): iterable
    {
        yield 'database profile, attachment, ttl 300' => ['database', 'attachment', 300];
        yield 'database profile, inline, ttl 60' => ['database', 'inline', 60];
        yield 'streamed config profile' => ['primary', 'attachment', 300];
        yield 'redirect config profile, attachment' => ['cdn', 'attachment', 300];
        yield 'redirect config profile, inline, ttl 60' => ['cdn', 'inline', 60];
    }

    #[DataProvider('setups')]
    #[IgnoreDeprecations]
    public function testTheNewPathAnswersExactlyLikeVersion140(string $profile, string $disposition, int $ttl): void
    {
        $kernel = $this->bootEquivalence($profile, $disposition, $ttl);
        $files  = $this->upload($kernel, 'b1');
        $other  = $this->upload($kernel, 'b2')[0];
        $alice  = $this->client($kernel, 'alice');
        $this->http($alice, '/storage/files/00000000-0000-4000-8000-000000000000/original'); // session cookie set once
        $legacy = $this->service($kernel, FileDeliveryV140::class);
        $new    = $this->service($kernel, FileDelivery::class);
        $port   = $this->service($kernel, FileDeliveryInterface::class);
        $html   = new FileDeliveryResponder();
        $cases  = 0;

        foreach ($files as $file) {
            foreach ([...StoredFile::VARIANTS, 'huge'] as $variant) {
                // HTTP: the 1.4.0 controller and the 1.5 controller (unknown variants: both routes answer 404).
                [$old, $cur] = $this->sameSecond(fn (): array => [
                    $this->http($alice, '/legacy/files/' . $file->getUuid() . '/' . $variant),
                    $this->http($alice, '/storage/files/' . $file->getUuid() . '/' . $variant),
                ]);
                self::assertSame($old, $cur, $file->getOriginalName() . ' / ' . $variant . ': HTTP');

                // Services: v1.4.0 respond(), the deprecated 1.5 respond() and the port + HttpFoundation adapter.
                [$v140, $deprecated, $ported] = $this->sameSecond(fn (): array => [
                    $this->snapshot($legacy->respond($file, $variant)),
                    $this->snapshot($new->respond($file, $variant)),
                    $variant === 'huge' ? null : $this->snapshot($html->toResponse($port->deliver($file->getUuid(), $variant))),
                ]);
                self::assertSame($v140, $deprecated, $file->getOriginalName() . ' / ' . $variant . ': deprecated respond()');
                if ($ported !== null) {
                    self::assertSame($v140, $ported, $file->getOriginalName() . ' / ' . $variant . ': port');
                }
                ++$cases;
            }
        }
        self::assertSame(16, $cases);
        self::assertSame($profile === 'cdn' ? 302 : 200, $this->http($alice, '/storage/files/' . $files[0]->getUuid() . '/original')['status']);

        // A context the viewer may not read: 404 on both, identical.
        self::assertSame($this->http($alice, '/legacy/files/' . $other->getUuid() . '/original'), $this->http($alice, '/storage/files/' . $other->getUuid() . '/original'));
        self::assertSame(404, $this->http($alice, '/storage/files/' . $other->getUuid() . '/original')['status']);
    }

    #[IgnoreDeprecations]
    public function testABucketThatIsNotConnectableAnyMoreAnswers404OnEveryPath(): void
    {
        $kernel = $this->bootEquivalence('primary', 'attachment', 300);
        $file   = $this->upload($kernel, 'b1')[0];
        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE storage_kit_profile SET config_name = 'gone'");
        $this->service($kernel, StorageProfiles::class)->reset();
        $alice = $this->client($kernel, 'alice');
        $this->http($alice, '/storage/files/00000000-0000-4000-8000-000000000000/original'); // session cookie set once

        $old = $this->http($alice, '/legacy/files/' . $file->getUuid() . '/original');
        self::assertSame(404, $old['status']);
        self::assertSame($old, $this->http($alice, '/storage/files/' . $file->getUuid() . '/original'));

        $this->entityManager($kernel)->clear();
        $file = $this->service($kernel, StoredFileRepository::class)->findOneByUuid($file->getUuid());
        self::assertInstanceOf(StoredFile::class, $file);
        foreach ([$this->service($kernel, FileDeliveryV140::class), $this->service($kernel, FileDelivery::class)] as $delivery) {
            try {
                $delivery->respond($file, 'original');
                self::fail('404 expected');
            } catch (NotFoundHttpException $e) {
                self::assertSame('', $e->getMessage());
                self::assertSame(404, $e->getStatusCode());
            }
        }
    }

    /**
     * Runs `$pair` until both sides ran within the same second (a pre-signed URL carries its expiry second).
     *
     * @template T of array
     *
     * @param callable(): T $pair
     *
     * @return T
     */
    private function sameSecond(callable $pair): array
    {
        do {
            $start  = time();
            $result = $pair();
        } while (time() !== $start);

        return $result;
    }

    /**
     * @return array{status: int, headers: array<string, list<string|null>>, body: string}
     */
    private function http(KernelBrowser $client, string $path): array
    {
        $client->request('GET', $path);
        $response = $client->getResponse();
        $headers  = $response->headers->all();
        unset($headers['date']);

        // An error page of the test kernel dumps the exception (with the request URI): only its status and headers count.
        $status = $response->getStatusCode();

        return ['status' => $status, 'headers' => $headers, 'body' => $status >= 400 ? '(error page)' : $client->getInternalResponse()->getContent()];
    }

    /**
     * @return array{class: class-string, status: int, headers: array<string, list<string|null>>, body: string}
     */
    private function snapshot(Response $response): array
    {
        $headers = $response->headers->all();
        unset($headers['date']);
        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        return ['class' => $response::class, 'status' => $response->getStatusCode(), 'headers' => $headers, 'body' => $body];
    }

    /**
     * @return list<StoredFile> one file per {@see self::NAMES} entry in board `$board`
     */
    private function upload(StorageKitTestKernel $kernel, string $board): array
    {
        $uploader = $this->service($kernel, ImageUploaderInterface::class);
        $files    = [];
        foreach (self::NAMES as $i => $name) {
            $bytes = match ($i % 4) {
                0       => Images::png(40, 30),
                1       => Images::gif(30, 40),
                2       => Images::jpeg(800, 500),
                default => Images::webp(10, 10),
            };
            $view = $uploader->uploadImage(new InMemoryUser($board === 'b1' ? 'alice' : 'bob', null), 'board', $board, $bytes, $name);
            if ($i === 3) {
                // A stored type outside the image allowlist (an older row, a hand-edited one): streamed as
                // application/octet-stream with a .bin name.
                $this->service($kernel, StorageEntityManager::class)->connection()->executeStatement("UPDATE storage_kit_file SET mime_type = 'image/svg+xml' WHERE uuid = ?", [$view->uuid]);
            }
            $file = $this->service($kernel, StoredFileRepository::class)->findOneByUuid($view->uuid);
            self::assertInstanceOf(StoredFile::class, $file);
            $files[] = $file;
            if ($board !== 'b1') {
                break;
            }
        }

        return $files;
    }

    private function bootEquivalence(string $profile, string $disposition, int $ttl): StorageKitTestKernel
    {
        $config = ['download' => ['disposition' => $disposition, 'url_ttl' => $ttl]];
        if ($profile !== 'database') {
            $config += ['config_profile_provider' => HostBucketProfiles::class, 'instance_profile' => $profile];
        }
        $kernel = $this->bootKernel(
            $config,
            $profile === 'database' ? [] : ['settings'],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_storage_kit');
                $routes->add('legacy_v140_download', '/legacy/files/{uuid}/{variant}')
                    ->controller([FileDownloadControllerV140::class, 'download'])
                    ->requirements(['uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}', 'variant' => 'original|preview|thumb'])
                    ->methods(['GET']);
            },
            extra: static function (ContainerConfigurator $container) use ($ttl, $disposition): void {
                $services = $container->services();
                $services->set(HostBucketProfiles::class)->public();
                $services->set(FileDeliveryV140::class)->public()->args([service(StorageProfiles::class), service(FileStorage::class), $ttl, $disposition]);
                $services->set(FileDownloadControllerV140::class)->public()->tag('controller.service_arguments')->args([
                    service(AccessGuard::class),
                    service(StoredFileRepository::class),
                    service(FileContexts::class),
                    service(FileDeliveryV140::class),
                ]);
            },
        );
        if ($profile === 'database') {
            $this->profile($kernel);
        }

        return $kernel;
    }
}
