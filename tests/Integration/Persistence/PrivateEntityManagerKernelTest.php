<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Tests\Fixtures\Entity\Note;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\Deprecations;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_filter;
use function array_values;
use function memory_get_usage;
use function str_contains;

/**
 * 1.8 (REQ-ARCH-001 rule 6 deprecation layer) on every CI database: `doctrine.private_entity_manager`.
 *
 * - Host mode (unset / false): the bundle's flush of the default manager writes the application's pending changes, as in
 *   1.x, and the host-flush probe reports each application class once per unit of work (again after a boundary).
 * - Private mode (true): the bundle's flushes write only its own rows; the application's pending changes stay pending
 *   in its manager, which stays open and is never cleared. A kept-alive kernel (worker mode, kernel reset off) drops
 *   the private manager at every request boundary and replaces it when closed.
 */
final class PrivateEntityManagerKernelTest extends KernelTestCase
{
    private const PROBE = 'Your pending changes to ' . Note::class . ' were flushed by NowoStorageKitBundle; 2.0 no longer does.';

    public function testHostModeFlushesYourPendingChangesAndTheProbeReportsThemOncePerUnit(): void
    {
        $kernel = null;
        $boot   = Deprecations::collect(function () use (&$kernel): void {
            $kernel = $this->bootKernel(['doctrine' => ['private_entity_manager' => null]]);
        });
        self::assertInstanceOf(StorageKitTestKernel::class, $kernel);
        self::assertContains('Since nowo-tech/storage-kit-bundle 1.8: ' . NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_UNSET_DEPRECATION, $boot);
        $storage = $this->service($kernel, StorageEntityManager::class);
        self::assertFalse($storage->isPrivate());
        $host = $this->entityManager($kernel);
        self::assertSame($host, $storage->manager());

        $host->persist(new Note('b1', 'pending'));
        $messages = Deprecations::collect(static function () use ($storage): void {
            $storage->transactional(static fn (): null => null);
            $storage->flush();
        });
        self::assertSame([self::PROBE], $this->probeHits($messages), 'one per class and unit of work');
        self::assertSame(1, $this->notes($storage->connection()), '1.x: the bundle flush wrote the application\'s note');

        // The next unit of work (a new main request) reports again.
        $this->service($kernel, 'Nowo\StorageKitBundle\EventListener\RequestBoundaryListener')->reset();
        $host->persist(new Note('b1', 'second'));
        self::assertSame([self::PROBE], $this->probeHits(Deprecations::collect(static fn () => $storage->transactional(static fn (): null => null))));

        // Nothing pending: nothing reported.
        $this->service($kernel, 'Nowo\StorageKitBundle\EventListener\RequestBoundaryListener')->reset();
        self::assertSame([], $this->probeHits(Deprecations::collect(static fn () => $storage->transactional(static fn (): null => null))));
    }

    public function testExplicitFalseKeepsHostModeWithItsOwnNoticeAndTheProbe(): void
    {
        $kernel = null;
        $boot   = Deprecations::collect(function () use (&$kernel): void {
            $kernel = $this->bootKernel(['doctrine' => ['private_entity_manager' => false]]);
        });
        self::assertInstanceOf(StorageKitTestKernel::class, $kernel);
        self::assertContains('Since nowo-tech/storage-kit-bundle 1.8: ' . NowoStorageKitExtension::PRIVATE_ENTITY_MANAGER_FALSE_DEPRECATION, $boot);
        $storage = $this->service($kernel, StorageEntityManager::class);
        $this->entityManager($kernel)->persist(new Note('b1', 'pending'));

        self::assertSame([self::PROBE], $this->probeHits(Deprecations::collect(static fn () => $storage->transactional(static fn (): null => null))));
    }

    public function testPrivateModeNeverWritesYourPendingChanges(): void
    {
        $kernel  = $this->bootKernel(['doctrine' => ['private_entity_manager' => true]]);
        $storage = $this->service($kernel, StorageEntityManager::class);
        self::assertTrue($storage->isPrivate());
        $host    = $this->entityManager($kernel);
        $profile = $this->profile($kernel);
        $id      = (int) $profile->getId();
        $note    = new Note('b1', 'pending');
        $host->persist($note);

        $messages = Deprecations::collect(function () use ($storage, $kernel, $id): void {
            $this->service($kernel, ImageUploaderInterface::class)->uploadImage(new InMemoryUser('alice', null), 'board', 'b1', Images::png(10, 10), 'a.png');
            $storage->transactional(static function () use ($storage, $id): void {
                $row = $storage->manager()->find(StorageProfile::class, $id);
                self::assertInstanceOf(StorageProfile::class, $row);
                $row->retire();
            });
        });

        self::assertSame([], $this->probeHits($messages), 'the private manager is never probed');
        $connection = $storage->connection();
        self::assertSame(StorageProfile::STATE_RETIRED, $connection->fetchOne('SELECT state FROM storage_kit_profile WHERE id = ?', [$id]), 'the bundle row is written');
        self::assertSame(0, $this->notes($connection), 'your pending note is not');
        self::assertTrue($host->isOpen());
        self::assertTrue($host->contains($note), 'your manager is never cleared');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($note), 'still pending: your flush writes it');
        $host->flush();
        self::assertSame(1, $this->notes($connection));
    }

    public function testAFailedPrivateWriteClosesOnlyThePrivateManager(): void
    {
        $kernel  = $this->bootKernel(['doctrine' => ['private_entity_manager' => true]]);
        $storage = $this->service($kernel, StorageEntityManager::class);
        $host    = $this->entityManager($kernel);
        $this->profile($kernel);
        $private = $storage->manager();
        self::assertInstanceOf(PrivateEntityManager::class, $private);
        $first = $private->live();

        try {
            $storage->transactional(static function () use ($storage): never {
                $storage->manager()->persist(new StorageProfile('acme'));

                throw new RuntimeException('failed write');
            });
        } catch (RuntimeException) {
        }
        $private->close();
        self::assertTrue($host->isOpen(), 'the application\'s manager stays open');
        self::assertNotSame($first, $private->live(), 'a closed private manager is replaced on its next use');
        self::assertCount(1, $storage->manager()->getRepository(StorageProfile::class)->findAll());
    }

    public function testAKeptAliveKernelDropsThePrivateManagerAtEveryRequestBoundary(): void
    {
        $kernel  = $this->bootKernel(['doctrine' => ['private_entity_manager' => true]]);
        $storage = $this->service($kernel, StorageEntityManager::class);
        $private = $storage->manager();
        self::assertInstanceOf(PrivateEntityManager::class, $private);
        $this->profile($kernel);
        $uploader = $this->service($kernel, ImageUploaderInterface::class);
        $uploader->uploadImage(new InMemoryUser('alice', null), 'board', 'b1', Images::png(10, 10), 'a.png');

        $baseline = 0;
        for ($i = 0; $i < 150; ++$i) {
            // One request served by the same kernel, without the services resetter (FrankenPHP worker, reset off).
            $request  = Request::create('/no-such-page-' . $i);
            $response = $kernel->handle($request);
            // Inside the request, the bundle loads its rows through the private manager...
            $files = $storage->manager()->getRepository(StoredFile::class)->findAll();
            self::assertCount(1, $files);
            self::assertTrue($private->isCreated());
            $kernel->terminate($request, $response);
            // ...and nothing of it survives the boundary.
            self::assertFalse($private->isCreated(), 'dropped at kernel.terminate (request ' . $i . ')');
            if ($i === 49) {
                $baseline = memory_get_usage();
            }
        }
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $baseline, 'no per-request growth');
    }

    /**
     * @param list<string> $messages
     *
     * @return list<string>
     */
    private function probeHits(array $messages): array
    {
        return array_values(array_filter($messages, static fn (string $message): bool => str_contains($message, 'were flushed by NowoStorageKitBundle')));
    }

    private function notes(Connection $connection): int
    {
        return (int) $connection->fetchOne('SELECT COUNT(*) FROM test_note');
    }
}
