<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;
use Doctrine\ORM\Id\IdentityGenerator;
use Doctrine\ORM\Id\SequenceGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StoredFileReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 1.6.1: the sequence a raw insert has to take the id from (PostgreSQL with DBAL 3), nothing otherwise.
 */
final class SequenceNameTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractIdGenerator, AbstractPlatform, array<string, string>, ?string}>
     */
    public static function cases(): iterable
    {
        yield 'sequence on PostgreSQL' => [new SequenceGenerator('ref_id_seq', 1), new PostgreSQLPlatform(), ['sequenceName' => 'ref_id_seq'], 'ref_id_seq'];
        yield 'sequence without a name' => [new SequenceGenerator('x', 1), new PostgreSQLPlatform(), [], null];
        yield 'sequence on another platform' => [new SequenceGenerator('ref_id_seq', 1), new SQLitePlatform(), ['sequenceName' => 'ref_id_seq'], null];
        yield 'identity column' => [new IdentityGenerator(), new PostgreSQLPlatform(), [], null];
    }

    /**
     * @param array<string, string> $definition
     */
    #[DataProvider('cases')]
    public function testSequenceName(AbstractIdGenerator $generator, AbstractPlatform $platform, array $definition, ?string $expected): void
    {
        $metadata                              = new ClassMetadata(StoredFileReference::class);
        $metadata->idGenerator                 = $generator;
        $metadata->sequenceGeneratorDefinition = $definition === [] ? null : ['sequenceName' => $definition['sequenceName'], 'allocationSize' => '1', 'initialValue' => '1'];
        $connection                            = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        self::assertSame($expected, (new StorageEntityManager($registry))->sequenceName(StoredFileReference::class));
    }
}
