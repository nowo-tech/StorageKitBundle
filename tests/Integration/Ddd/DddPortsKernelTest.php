<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Ddd;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\StorageKitBundle\Download\FileDeliveryInterface;
use Nowo\StorageKitBundle\Download\FileNotDeliverable;
use Nowo\StorageKitBundle\Download\StoredImageResolverInterface;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\PersonalData\StoredFilesPersonalDataInterface;
use Nowo\StorageKitBundle\Reference\FileReferencesInterface;
use Nowo\StorageKitBundle\Tests\Fixtures\Ddd\HostAttachments;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\HostBucketProfiles;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_column;
use function array_keys;
use function gc_collect_cycles;
use function memory_get_usage;
use function ob_get_clean;
use function ob_start;
use function str_starts_with;
use function strtoupper;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * 1.5 DDD / hexagonal host (REQ-ARCH-001): no Instance Settings, named buckets from its own provider, the bundle
 * entities on a dedicated entity manager, its notes never Doctrine entities the bundle sees (referenced by ID through
 * {@see FileReferencesInterface}), and an application service ({@see HostAttachments}) that uses the 1.5 ports only.
 * Then the same kernel kept alive as a FrankenPHP worker (kernel reset false: `http_kernel` handle / terminate, no
 * services resetter) serves 1,200 downloads alternating two users, each answer holding only what that user may read,
 * with the bundle manager empty after every request and flat memory (REQ-RUNTIME-002).
 */
final class DddPortsKernelTest extends KernelTestCase
{
    public function testAHostUsesTheBundleThroughItsPortsOnly(): void
    {
        $kernel = $this->bootDdd();
        $host   = $this->service($kernel, HostAttachments::class);
        $alice  = new InMemoryUser('alice', null);
        $png    = Images::png(40, 20);

        $uuid  = $host->attach($alice, 'b1', $png, 'diagram.png');
        $other = $host->attach(new InMemoryUser('bob', null), 'b2', Images::png(10, 10), 'private.png');
        $text  = 'See ![diagram](storage-file:' . $uuid . ') and ![x](storage-file:' . $other . ')';
        $host->noteSaved('n-1', 'b1', $text);

        // Rendering: only what the viewer may read.
        self::assertSame([$uuid], array_keys($host->render($text, $alice)));
        self::assertSame('/storage/files/' . $uuid . '/preview', $host->render($text, $alice)[$uuid]->src);
        self::assertSame([], $host->render($text, new InMemoryUser('guest', null)));
        // Download through the port: the stored bytes, no HttpFoundation type.
        $stored = $this->buckets($kernel)->objects('host-files', 'http://minio:9000');
        self::assertContains($host->download($uuid, StoredFile::VARIANT_ORIGINAL), $stored, 'the stored object');
        self::assertNotSame($png, $host->download($uuid, StoredFile::VARIANT_ORIGINAL), 're-encoded at upload');
        self::assertStringStartsWith("\x89PNG", $host->download(strtoupper($uuid), StoredFile::VARIANT_THUMB));
        foreach ([[$uuid, 'huge'], ['00000000-0000-4000-8000-000000000000', 'original']] as [$id, $variant]) {
            try {
                $host->download($id, $variant);
                self::fail('not deliverable');
            } catch (FileNotDeliverable) {
            }
        }
        // GDPR by plain strings.
        self::assertSame([$uuid], array_column($host->export('alice', null), 'uuid'));
        self::assertTrue($host->export('alice', null)[0]['in_use']);
        $host->noteDeleted('n-1');
        self::assertSame(1, $host->erase('alice', null));
        self::assertSame([], $host->export('alice', null));

        // The bundle tables live on the dedicated manager only; the host manager maps none of them.
        $registry = $this->service($kernel, 'doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $default = $registry->getManager('default');
        self::assertInstanceOf(EntityManagerInterface::class, $default);
        foreach ($default->getMetadataFactory()->getAllMetadata() as $metadata) {
            self::assertFalse(str_starts_with($metadata->getName(), 'Nowo\\StorageKitBundle\\'), $metadata->getName());
        }
    }

    public function testAKeptAliveWorkerAnswersEachUserWithTheirOwnFilesAndFlatMemory(): void
    {
        $kernel = $this->bootDdd();
        $host   = $this->service($kernel, HostAttachments::class);
        $shared = $host->attach(new InMemoryUser('alice', null), 'b1', Images::png(30, 30), 'shared.png');
        $bobs   = $host->attach(new InMemoryUser('bob', null), 'b2', Images::png(31, 30), 'bob-only.png');
        $bytes  = [$shared => $host->download($shared, 'original'), $bobs => $host->download($bobs, 'original')];
        $http   = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $dispatcher = $this->service($kernel, 'event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $registry = $this->service($kernel, 'doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $before = 0;

        for ($i = 0; $i < 600; ++$i) {
            $user = $i % 2 === 0 ? 'alice' : 'bob';
            foreach ([$shared => 200, $bobs => $user === 'bob' ? 200 : 404] as $uuid => $status) {
                $request  = Request::create('/storage/files/' . $uuid . '/original', server: ['REMOTE_ADDR' => '203.0.113.9', 'PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => 'pw']);
                $response = $http->handle($request);
                self::assertSame($status, $response->getStatusCode(), $user . ' #' . $i);
                if ($status === 200) {
                    ob_start();
                    $response->sendContent();
                    self::assertSame($bytes[$uuid], ob_get_clean(), $user . ' #' . $i);
                }
                $http->terminate($request, $response);
                $manager = $registry->getManager('storage_kit');
                self::assertInstanceOf(EntityManagerInterface::class, $manager);
                self::assertSame(0, $manager->getUnitOfWork()->size(), 'the bundle manager is cleared after every request');
            }
            if ($i % 100 === 50) {
                // A Messenger message and a console command end in the same worker: boundaries too.
                $dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new stdClass()), 'async'));
                $dispatcher->dispatch(new ConsoleTerminateEvent(new Command('host:job'), new ArrayInput([]), new NullOutput(), 0), ConsoleEvents::TERMINATE);
            }
            if ($i === 99) {
                gc_collect_cycles();
                $before = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $before, 'Memory must stay flat over 1,000 requests.');
    }

    private function bootDdd(): StorageKitTestKernel
    {
        return $this->bootKernel(
            ['config_profile_provider' => HostBucketProfiles::class, 'instance_profile' => 'primary', 'doctrine' => ['entity_manager' => 'storage_kit']],
            ['settings'],
            extra: static function (ContainerConfigurator $container): void {
                $services = $container->services();
                $services->set(HostBucketProfiles::class)->public();
                $services->set(HostAttachments::class)->public()->args([
                    service(ImageUploaderInterface::class),
                    service(FileReferencesInterface::class),
                    service(StoredImageResolverInterface::class),
                    service(FileDeliveryInterface::class),
                    service(StoredFilesPersonalDataInterface::class),
                ]);
            },
            orm: [
                'default_entity_manager' => 'default',
                'entity_managers'        => [
                    'default'     => ['connection' => 'default', 'auto_mapping' => true],
                    'storage_kit' => ['connection' => 'default'],
                ],
            ],
        );
    }
}
