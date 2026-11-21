<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\StorageKitBundle\DependencyInjection\Compiler\StoragePrivateEntityManagerPass;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Sync\StorageMove;
use Nowo\StorageKitBundle\Sync\StorageSyncer;
use Nowo\StorageKitBundle\Tests\Fixtures\Entity\Note;
use Nowo\StorageKitBundle\Tests\Fixtures\HostRelations\Product;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\StorageKitTestKernel;
use Nowo\StorageKitBundle\Tests\Support\Deprecations;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Nowo\StorageKitBundle\Upload\ImageUploaderInterface;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use RuntimeException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_filter;
use function array_values;
use function memory_get_usage;
use function sprintf;
use function str_contains;

/**
 * 2.0 (REQ-ARCH-001 rule 6): the private entity manager is the only mode, on every CI database.
 *
 * - The setting table through the real `Bundle::build()`: unset (the private manager, no notice), `true` (the same,
 *   with a "no effect" notice), `false` (build error), a dedicated manager (used directly), a dedicated manager with
 *   `true` (the private manager on it, as in 1.8) and with `false` (no effect, notice).
 * - The bundle's flushes write only its own rows; the application's pending changes stay pending in its manager, which
 *   stays open and is never flushed, cleared or detached by the bundle.
 * - Lesson W1-2: an application entity related to a bundle entity through its own manager's `getReference()` flushes
 *   its foreign key; a bundle entity the application loaded through its own manager (initialised, or an
 *   uninitialised proxy) is loaded again by the bundle's manager: no duplicate row, the application's instance stays
 *   its own.
 * - A kept-alive kernel (worker mode, kernel reset off) drops the private manager at every request boundary and
 *   replaces it when closed.
 */
final class PrivateEntityManagerKernelTest extends KernelTestCase
{
    public function testUnsetWritesThroughThePrivateManagerWithoutNotice(): void
    {
        $kernel = null;
        $boot   = Deprecations::collect(function () use (&$kernel): void {
            $kernel = $this->bootKernel();
        });
        self::assertInstanceOf(StorageKitTestKernel::class, $kernel);
        self::assertSame([], $this->storageNotices($boot));
        $storage = $this->service($kernel, StorageEntityManager::class);
        self::assertTrue($storage->isPrivate());
        self::assertNotSame($this->entityManager($kernel), $storage->manager());
    }

    public function testTrueHasNoEffectAndSaysSo(): void
    {
        $kernel = null;
        $boot   = Deprecations::collect(function () use (&$kernel): void {
            $kernel = $this->bootKernel(['doctrine' => ['private_entity_manager' => true]]);
        });
        self::assertInstanceOf(StorageKitTestKernel::class, $kernel);
        self::assertSame(['Since nowo-tech/storage-kit-bundle 2.0: ' . StoragePrivateEntityManagerPass::TRUE_NO_EFFECT], $this->storageNotices($boot));
        self::assertTrue($this->service($kernel, StorageEntityManager::class)->isPrivate());
    }

    public function testFalseFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(StoragePrivateEntityManagerPass::FALSE_REMOVED);
        $this->bootKernel(['doctrine' => ['private_entity_manager' => false]]);
    }

    public function testADedicatedManagerIsUsedDirectlyAndWithTrueCarriesThePrivateManager(): void
    {
        $dedicated = $this->bootKernel(['doctrine' => ['entity_manager' => 'storage_kit']], orm: $this->twoManagers());
        $storage   = $this->service($dedicated, StorageEntityManager::class);
        self::assertFalse($storage->isPrivate());
        self::assertSame($this->registry($dedicated)->getManager('storage_kit'), $storage->manager());

        $kernel = null;
        $boot   = Deprecations::collect(function () use (&$kernel): void {
            $kernel = $this->bootKernel(['doctrine' => ['entity_manager' => 'storage_kit', 'private_entity_manager' => true]], orm: $this->twoManagers());
        });
        self::assertInstanceOf(StorageKitTestKernel::class, $kernel);
        self::assertSame([], $this->storageNotices($boot), 'true selects the private manager on a dedicated one: no notice');
        $private = $this->service($kernel, StorageEntityManager::class);
        self::assertTrue($private->isPrivate());
        self::assertNotSame($this->registry($kernel)->getManager('storage_kit'), $private->manager());
        $this->profile($kernel);
        self::assertSame(1, (int) $private->connection()->fetchOne('SELECT COUNT(*) FROM storage_kit_profile'));
    }

    public function testFalseWithADedicatedManagerHasNoEffectAndSaysSo(): void
    {
        $kernel = null;
        $boot   = Deprecations::collect(function () use (&$kernel): void {
            $kernel = $this->bootKernel(['doctrine' => ['entity_manager' => 'storage_kit', 'private_entity_manager' => false]], orm: $this->twoManagers());
        });
        self::assertInstanceOf(StorageKitTestKernel::class, $kernel);
        self::assertSame(['Since nowo-tech/storage-kit-bundle 2.0: ' . sprintf(StoragePrivateEntityManagerPass::FALSE_NO_EFFECT, 'storage_kit')], $this->storageNotices($boot));
        $storage = $this->service($kernel, StorageEntityManager::class);
        self::assertFalse($storage->isPrivate());
        self::assertSame($this->registry($kernel)->getManager('storage_kit'), $storage->manager());
    }

    public function testTheBundleNeverWritesClearsOrDetachesYourPendingChanges(): void
    {
        $kernel  = $this->bootRelations();
        $storage = $this->service($kernel, StorageEntityManager::class);
        $host    = $this->entityManager($kernel);
        $profile = $this->profile($kernel);
        $id      = (int) $profile->getId();
        $product = new Product('kept');
        $host->persist($product);
        $host->flush();
        $product->rename('changed, not flushed');
        $note = new Note('b1', 'pending');
        $host->persist($note);

        $messages = Deprecations::collect(function () use ($storage, $kernel, $id): void {
            $this->service($kernel, ImageUploaderInterface::class)->uploadImage(new InMemoryUser('alice', null), 'board', 'b1', Images::png(10, 10), 'a.png');
            $storage->transactional(static function () use ($storage, $id): void {
                $row = $storage->manager()->find(StorageProfile::class, $id);
                self::assertInstanceOf(StorageProfile::class, $row);
                $row->retire();
            });
        });

        self::assertSame([], $this->storageNotices($messages));
        $connection = $storage->connection();
        self::assertSame(StorageProfile::STATE_RETIRED, $connection->fetchOne('SELECT state FROM storage_kit_profile WHERE id = ?', [$id]), 'the bundle row is written');
        self::assertSame(0, $this->notes($connection), 'your pending note is not');
        self::assertSame('kept', $connection->fetchOne('SELECT name FROM test_product WHERE id = ?', [$product->getId()]), 'nor your pending change');
        self::assertTrue($host->isOpen());
        self::assertTrue($host->contains($note), 'your manager is never cleared');
        self::assertTrue($host->contains($product), 'nor detached');
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($note), 'still pending: your flush writes it');
        $host->flush();
        self::assertSame(1, $this->notes($connection));
        self::assertSame('changed, not flushed', $connection->fetchOne('SELECT name FROM test_product WHERE id = ?', [$product->getId()]));
    }

    public function testYourEntityReferencesAFileThroughYourOwnManager(): void
    {
        $kernel = $this->bootRelations();
        $this->profile($kernel);
        $host = $this->entityManager($kernel);
        $view = $this->service($kernel, ImageUploaderInterface::class)->uploadImage(new InMemoryUser('alice', null), 'board', 'b1', Images::png(10, 10), 'a.png');
        $id   = (int) $this->service($kernel, StorageEntityManager::class)->connection()->fetchOne('SELECT id FROM storage_kit_file WHERE uuid = ?', [$view->uuid]);

        $product = new Product('with image');
        $product->setImage($host->getReference(StoredFile::class, $id));
        $host->persist($product);
        $host->flush();

        self::assertSame($id, (int) $host->getConnection()->fetchOne('SELECT image_id FROM test_product WHERE id = ?', [$product->getId()]));
        self::assertSame(1, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM storage_kit_file'), 'no second file row');
    }

    public function testABundleEntityLoadedByYourManagerIsLoadedAgainByTheBundle(): void
    {
        $kernel = $this->bootRelations();
        $active = $this->profile($kernel);
        $target = $this->profile($kernel, null, 'target', StorageProfile::STATE_TARGET);
        [$activeId, $targetId] = [(int) $active->getId(), (int) $target->getId()];
        $this->clearManagers($kernel);
        $host = $this->entityManager($kernel);

        // An initialised instance and an uninitialised proxy, both held by your manager.
        $hostActive = $host->find(StorageProfile::class, $activeId);
        $hostTarget = $host->getReference(StorageProfile::class, $targetId);
        self::assertInstanceOf(StorageProfile::class, $hostActive);
        self::assertInstanceOf(StorageProfile::class, $hostTarget);
        self::assertTrue($host->getUnitOfWork()->isUninitializedObject($hostTarget));

        $sync = $this->service($kernel, StorageSyncer::class)->open($hostActive, $hostTarget);

        $connection = $host->getConnection();
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM storage_kit_profile'), 'no duplicate profile row');
        self::assertSame([$activeId, $targetId], [(int) $connection->fetchOne('SELECT from_id FROM storage_kit_sync'), (int) $connection->fetchOne('SELECT to_id FROM storage_kit_sync')]);
        self::assertTrue($this->bundleManager($kernel)->contains($sync));
        self::assertNotSame($hostActive, $sync->getFrom(), 'the bundle uses its own instance');
        self::assertTrue($host->contains($hostActive), 'yours stays in your manager');
        self::assertTrue($host->getUnitOfWork()->isUninitializedObject($hostTarget), 'your proxy is not even initialised');

        // A bundle write service with your instance: the same result as with the bundle's own.
        self::assertTrue($this->service($kernel, StorageSyncer::class)->cancel($host->find(StorageSync::class, (int) $sync->getId()) ?? self::fail('sync')));
        self::assertSame(StorageSync::CANCELLED, $connection->fetchOne('SELECT status FROM storage_kit_sync'));
        self::assertTrue($this->service($kernel, StorageMove::class)->discard($hostTarget, null));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM storage_kit_profile'), 'the target row is deleted, by id');
        self::assertTrue($host->contains($hostActive));
    }

    public function testARowThatIsGoneFailsClearly(): void
    {
        $kernel  = $this->bootRelations();
        $profile = $this->profile($kernel, null, 'gone', StorageProfile::STATE_TARGET);
        $id      = (int) $profile->getId();
        $this->clearManagers($kernel);
        $host     = $this->entityManager($kernel);
        $hostCopy = $host->find(StorageProfile::class, $id);
        self::assertInstanceOf(StorageProfile::class, $hostCopy);
        $host->getConnection()->executeStatement('DELETE FROM storage_kit_profile WHERE id = ?', [$id]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(StorageProfile::class . ' ' . $id . ' no longer exists.');
        $this->service($kernel, StorageEntityManager::class)->adopt($hostCopy);
    }

    public function testANewEntityIsNotLoadedAgain(): void
    {
        $kernel  = $this->bootKernel();
        $profile = new StorageProfile('acme', StorageProfile::STATE_TARGET);

        self::assertSame($profile, $this->service($kernel, StorageEntityManager::class)->adopt($profile));
    }

    public function testAFailedPrivateWriteClosesOnlyThePrivateManager(): void
    {
        $kernel  = $this->bootKernel();
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
        self::assertCount(1, $storage->manager()->getRepository(StorageProfile::class)->findAll(), 'no orphan row of the failed unit');
    }

    public function testAKeptAliveKernelDropsThePrivateManagerAtEveryRequestBoundary(): void
    {
        $kernel  = $this->bootKernel();
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
     * A kernel whose application entities include one related to a bundle entity (`Product::$image`).
     */
    private function bootRelations(): StorageKitTestKernel
    {
        return $this->bootKernel(orm: ['mappings' => [
            'Tests'         => StorageKitTestKernel::testsMapping(),
            'HostRelations' => [
                'type'      => 'attribute',
                'dir'       => '%kernel.project_dir%/tests/Fixtures/HostRelations',
                'prefix'    => 'Nowo\StorageKitBundle\Tests\Fixtures\HostRelations',
                'is_bundle' => false,
            ],
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function twoManagers(): array
    {
        return [
            'default_entity_manager' => 'default',
            'entity_managers'        => [
                'default'     => ['connection' => 'default', 'auto_mapping' => true, 'mappings' => ['Tests' => StorageKitTestKernel::testsMapping()]],
                'storage_kit' => ['connection' => 'default'],
            ],
        ];
    }

    private function registry(StorageKitTestKernel $kernel): ManagerRegistry
    {
        $registry = $this->service($kernel, 'doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    /**
     * @param list<string> $messages
     *
     * @return list<string>
     */
    private function storageNotices(array $messages): array
    {
        return array_values(array_filter($messages, static fn (string $message): bool => str_contains($message, 'nowo-tech/storage-kit-bundle') || str_contains($message, 'NowoStorageKitBundle')));
    }

    private function notes(Connection $connection): int
    {
        return (int) $connection->fetchOne('SELECT COUNT(*) FROM test_note');
    }

}
