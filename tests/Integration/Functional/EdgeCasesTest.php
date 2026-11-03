<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Functional;

use DateTimeImmutable;
use League\Flysystem\Config;
use Nowo\StorageKitBundle\Controller\StorageMoveController;
use Nowo\StorageKitBundle\Controller\StorageSettingsController;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Lifecycle\FilePurger;
use Nowo\StorageKitBundle\Message\RunStorageSyncMessage;
use Nowo\StorageKitBundle\MessageHandler\RunStorageSyncHandler;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Reference\ExistingIds;
use Nowo\StorageKitBundle\Reference\FileReferences;
use Nowo\StorageKitBundle\Repository\StorageSyncRepository;
use Nowo\StorageKitBundle\Repository\StoredFileRepository;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Sync\StorageSyncer;
use Nowo\StorageKitBundle\Tests\Fixtures\Entity\Note;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploader;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Defence-in-depth paths and API corners that the page flows do not reach.
 */
final class EdgeCasesTest extends KernelTestCase
{
    public function testTheControllersDenyOnTheirOwnToo(): void
    {
        $kernel   = $this->bootKernel();
        $settings = $this->service($kernel, StorageSettingsController::class);
        $move     = $this->service($kernel, StorageMoveController::class);
        $calls    = [
            static fn () => $settings->instance(new Request()),
            static fn () => $settings->testInstance(new Request()),
            static fn () => $move->instance(new Request()),
            static fn () => $move->instanceAction(new Request(), 'test'),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('allowed');
            } catch (AccessDeniedHttpException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAForwardedSubRequestIsCheckedToo(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel, 'alice');
        $client->request('GET', '/storage/files/00000000-0000-4000-8000-000000000000/preview');
        $request = Request::create('/elsewhere');
        $request->attributes->set('_controller', StorageSettingsController::class . '::instance');
        $request->setSession($client->getRequest()->getSession());
        $response = $kernel->handle($request, HttpKernelInterface::SUB_REQUEST);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testRepositoryAndReferenceCorners(): void
    {
        $kernel = $this->bootKernel();
        $files  = $this->service($kernel, StoredFileRepository::class);
        $this->profile($kernel);
        $this->service($kernel, ImageUploader::class)->upload(new InMemoryUser('alice', null), 'board', 'b1', Images::png(10, 10), 'a.png');

        self::assertSame([], $files->byUuids([]));
        self::assertSame(0, $files->bytesFor([]));
        self::assertGreaterThan(0, $files->bytesFor(['owner-b1', 'owner-b1']));
        self::assertSame(0, $files->bytesFor(['nobody']));
        self::assertSame(0, $this->service($kernel, FileReferences::class)->orphanUnreferenced([], new DateTimeImmutable()));
        self::assertSame([], ExistingIds::of($this->entityManager($kernel), Note::class, 'id) OR 1=1 --', ['x']));
        self::assertSame([], ExistingIds::of($this->entityManager($kernel), Note::class, 'id', []));
        self::assertNull($this->service($kernel, StorageProfiles::class)->configRow('nope'));
    }

    public function testAnUnreferencedFileWithoutDateBecomesAnOrphan(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $this->service($kernel, ImageUploader::class)->upload(new InMemoryUser('alice', null), 'board', 'b1', Images::png(10, 10), 'a.png');
        $this->entityManager($kernel)->getConnection()->executeStatement('UPDATE storage_kit_file SET orphaned_since = NULL');

        self::assertSame(1, $this->service($kernel, FilePurger::class)->run(new DateTimeImmutable())['orphaned']);
    }

    public function testUploaderCorners(): void
    {
        $kernel   = $this->bootKernel();
        $uploader = $this->service($kernel, ImageUploader::class);
        $this->profile($kernel);

        try {
            $uploader->upload(new InMemoryUser('alice', null), 'Bad Type', 'b1', Images::png(), 'a.png');
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::CONTEXT, $e->reason);
        }
        // The second object fails and the first cannot be removed: logged, still refused.
        $this->buckets($kernel)->writesLeft  = 1;
        $this->buckets($kernel)->failDeletes = true;
        try {
            $uploader->upload(new InMemoryUser('alice', null), 'board', 'b1', Images::png(2000, 1000), 'a.png');
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::STORAGE, $e->reason);
        }
        self::assertSame('image', ImageUploader::cleanName("\x01\x02"));
        $this->entityManager($kernel)->getConnection()->executeStatement("UPDATE storage_kit_profile SET region = 'BAD'");
        $this->entityManager($kernel)->clear();
        $this->service($kernel, StorageProfiles::class)->reset();
        try {
            $uploader->upload(new InMemoryUser('alice', null), 'board', 'b1', Images::png(), 'a.png');
            self::fail('accepted');
        } catch (UploadRefused $e) {
            self::assertSame(UploadRefused::OFF, $e->reason);
        }
    }

    public function testTheHandlerQueuesTheNextSlice(): void
    {
        $kernel   = $this->bootKernel();
        $from     = $this->profile($kernel);
        $to       = $this->profile($kernel, null, 'target', StorageProfile::STATE_TARGET);
        $uploader = $this->service($kernel, ImageUploader::class);
        $uploader->upload(new InMemoryUser('alice', null), 'board', 'b1', Images::png(10, 10), 'a.png');
        $uploader->upload(new InMemoryUser('alice', null), 'board', 'b1', Images::png(10, 10), 'b.png');
        $syncer = $this->service($kernel, StorageSyncer::class);
        $sync   = $syncer->open($from, $to);
        $bus    = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message);
            }
        };
        $sliced = new StorageSyncer(
            $this->service($kernel, StorageSyncRepository::class),
            $this->service($kernel, StorageEntityManager::class),
            $this->service($kernel, StorageProfiles::class),
            $this->service($kernel, FileStorage::class),
            null,
            1,
            checkScanLimit: 1,
        );
        (new RunStorageSyncHandler($this->service($kernel, StorageSyncRepository::class), $sliced, $bus, 0))(new RunStorageSyncMessage((int) $sync->getId()));

        self::assertCount(1, $bus->sent);
        self::assertSame(StorageSync::QUEUED, $sync->getStatus());
        self::assertTrue($sliced->check($from, $to)['truncated'] || $sliced->check($from, $to)['expected'] > 0);
        $this->buckets($kernel)->adapter('http://minio:9000|target')->write('app/a', 'x', new Config());
        $this->buckets($kernel)->adapter('http://minio:9000|target')->write('app/b', 'x', new Config());
        self::assertTrue($sliced->check($from, $to)['truncated']);
    }

    public function testAHostDefinedFormKitProfileIsKept(): void
    {
        $kernel = $this->bootKernel(extra: static function (ContainerConfigurator $container): void {
            $container->extension('nowo_form_kit', ['profiles' => ['storage_kit' => ['alias' => 'storage_kit', 'translation_domain' => 'NowoStorageKitBundle']]]);
        });
        $client = $this->client($kernel);
        $client->request('GET', '/admin/storage');

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testDisablingUploadsKeepsTheExistingProfile(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel, 'acme');
        $client  = $this->client($kernel, 'scopeadmin');
        $crawler = $client->request('GET', '/storage/scopes/acme');
        $form    = $crawler->selectButton('Save')->form();
        $field   = $form['storage_profile[enabled]'];
        self::assertInstanceOf(ChoiceFormField::class, $field);
        $field->untick();
        $client->submit($form);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('enabled', $this->audit($kernel)->events[0]->details['fields']);
    }
}
