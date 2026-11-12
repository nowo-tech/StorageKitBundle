<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Persistence;

use LogicException;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Download\FileDelivery;
use Nowo\StorageKitBundle\Download\FileNotDeliverable;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Storage\FileStorage;
use Nowo\StorageKitBundle\Tests\Fixtures\Entity\Note;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;

use function array_flip;
use function array_intersect_key;
use function range;

/**
 * 1.5 DBAL writes of {@see StorageEntityManager} (no flush): the guards (no identifier yet, unknown, identifier and
 * encrypted fields), integer and string identifiers, and the managed instance aligned with the row (nothing left for
 * a later flush). Plus the download port built without its repository.
 */
final class DbalWritesTest extends KernelTestCase
{
    public function testTheGuards(): void
    {
        $kernel  = $this->bootKernel();
        $writes  = $this->service($kernel, StorageEntityManager::class);
        $profile = $this->profile($kernel);
        $id      = (int) $profile->getId();

        self::assertSame(0, $writes->updateRows(StorageProfile::class, [], ['testOk' => true]));
        self::assertSame(0, $writes->updateRows(StorageProfile::class, [$id], []));
        foreach (['id' => 'is not a writable mapped field', 'nope' => 'is not a writable mapped field', 'secret' => 'is encrypted: it is never written with a DBAL statement'] as $field => $message) {
            try {
                $writes->updateRows(StorageProfile::class, [$id], [$field => 'x']);
                self::fail($field);
            } catch (LogicException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $field);
            }
        }
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no identifier yet: insert it first.');
        $writes->update(new StorageProfile(), StorageProfile::TEST_FIELDS);
    }

    public function testManagedInstancesShowTheWrittenValuesWithNothingLeftToFlush(): void
    {
        $kernel  = $this->bootKernel();
        $writes  = $this->service($kernel, StorageEntityManager::class);
        $manager = $writes->manager();
        $profile = $this->profile($kernel);
        $note    = new Note('b1', 'before');
        $manager->persist($note);
        $manager->flush();

        $profile->recordTest(true, true);
        $writes->update($profile, StorageProfile::TEST_FIELDS);
        self::assertSame(1, $writes->updateRows(Note::class, [$note->getId()], ['body' => 'after']));
        self::assertSame('after', $note->getBody());

        $manager->getUnitOfWork()->computeChangeSets();
        // (A freshly persisted profile still shows DoctrineEncrypt's plain / cipher pair for `secret`: not ours.)
        self::assertSame([], array_intersect_key($manager->getUnitOfWork()->getEntityChangeSet($profile), array_flip(StorageProfile::TEST_FIELDS)));
        self::assertSame([], $manager->getUnitOfWork()->getEntityChangeSet($note));
        $connection = $manager->getConnection();
        self::assertSame(['1', 'after'], [(string) $connection->fetchOne('SELECT test_ok FROM storage_kit_profile'), $connection->fetchOne('SELECT body FROM test_note')]);
        $manager->clear();
        self::assertTrue($manager->find(StorageProfile::class, $profile->getId())?->getTestOk());
    }

    public function testIdsAreWrittenInChunksAndAnUnreadablePropertyFailsClosed(): void
    {
        $kernel  = $this->bootKernel();
        $writes  = $this->service($kernel, StorageEntityManager::class);
        $profile = $this->profile($kernel);
        $ids     = range(100000, 100000 + 2 * StorageEntityManager::UPDATE_CHUNK + 5);
        $ids[]   = (int) $profile->getId();

        self::assertSame(1, $writes->updateRows(StorageProfile::class, $ids, ['testOk' => true]), 'three statements, one row');

        $metadata          = $writes->manager()->getClassMetadata(StorageProfile::class);
        $mapping           = $metadata->fieldMappings['testOk'];
        $declared          = $mapping->declared;
        $mapping->declared = 'Nowo\\StorageKitBundle\\Tests\\Missing\\Superclass'; // @phpstan-ignore assign.propertyType (no such class: the guard must fail closed)
        try {
            $writes->updateRows(StorageProfile::class, [(int) $profile->getId()], ['testOk' => false]);
            self::fail('fail closed');
        } catch (LogicException $e) {
            self::assertStringContainsString('is encrypted', $e->getMessage());
        } finally {
            $mapping->declared = $declared;
        }
    }

    public function testTheDownloadPortNeedsItsRepository(): void
    {
        $kernel   = $this->bootKernel();
        $delivery = new FileDelivery($this->service($kernel, StorageProfiles::class), $this->service($kernel, FileStorage::class));
        try {
            $delivery->deliver('00000000-0000-4000-8000-000000000000', 'huge');
            self::fail('unknown variant');
        } catch (FileNotDeliverable $e) {
            self::assertSame('Unknown file variant.', $e->getMessage());
        }
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('FileDelivery::deliver() needs the StoredFileRepository');
        $delivery->deliver('00000000-0000-4000-8000-000000000000', 'original');
    }
}
