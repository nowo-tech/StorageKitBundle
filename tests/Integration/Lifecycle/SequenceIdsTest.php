<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Integration\Lifecycle;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Id\SequenceGenerator;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StoredFileReference;
use Nowo\StorageKitBundle\Tests\Fixtures\Entity\Note;
use Nowo\StorageKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\StorageKitBundle\Tests\Support\Images;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * 1.6.1: raw DBAL inserts into bundle tables work where the ORM maps the id with a sequence (PostgreSQL with DBAL 3,
 * no column default) as well as with identity / autoincrement columns. The CI runs this on PostgreSQL with DBAL 3.
 */
final class SequenceIdsTest extends KernelTestCase
{
    public function testReferenceRowsGetAnIdWhateverTheIdStrategy(): void
    {
        $kernel = $this->bootKernel();
        $this->profile($kernel);
        $user  = new InMemoryUser('alice', null);
        $files = [];
        for ($i = 0; $i < 3; ++$i) {
            $files[] = $this->uploadFile($kernel, $user, 'board', 'b1', Images::png(2000, 1000), 'photo.png');
        }
        $body = '';
        foreach ($files as $file) {
            self::assertNotNull($file->getId());
            $body .= '![x](storage-file:' . $file->getUuid() . ') ';
        }
        $manager = $this->entityManager($kernel);
        $manager->persist(new Note('b1', $body));
        $manager->flush();

        $ids = $manager->getConnection()->fetchFirstColumn('SELECT id FROM storage_kit_file_reference ORDER BY id');
        self::assertCount(3, $ids);
        self::assertCount(3, array_unique($ids));
        foreach ($ids as $id) {
            self::assertGreaterThan(0, (int) $id);
        }

        $second = new Note('b1', 'second ![x](storage-file:' . $files[0]->getUuid() . ')');
        $manager->persist($second);
        $manager->flush();
        self::assertCount(4, array_unique($manager->getConnection()->fetchFirstColumn('SELECT id FROM storage_kit_file_reference')));
    }

    public function testSequenceNameOnlyForSequenceGeneratorsOnPostgreSql(): void
    {
        $kernel       = $this->bootKernel();
        $manager      = $this->entityManager($kernel);
        $name         = $this->service($kernel, StorageEntityManager::class)->sequenceName(StoredFileReference::class);
        $usesSequence = $manager->getClassMetadata(StoredFileReference::class)->idGenerator instanceof SequenceGenerator
            && $manager->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if ($usesSequence) {
            self::assertIsString($name);
            self::assertNotSame('', $name);
        } else {
            self::assertNull($name);
        }
    }
}
