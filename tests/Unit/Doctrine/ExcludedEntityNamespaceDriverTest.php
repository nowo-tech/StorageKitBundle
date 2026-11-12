<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Persistence\Mapping\MappingException;
use Nowo\StorageKitBundle\DependencyInjection\Compiler\StorageEntityManagerPass;
use Nowo\StorageKitBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Tests\Fixtures\Entity\Note;
use PHPUnit\Framework\TestCase;

final class ExcludedEntityNamespaceDriverTest extends TestCase
{
    public function testMapsNothing(): void
    {
        $driver = new ExcludedEntityNamespaceDriver();

        self::assertTrue($driver->isTransient(StoredFile::class));
        self::assertSame([], $driver->getAllClassNames());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(StoredFile::class);
        $driver->loadMetadataForClass(StoredFile::class, new ClassMetadata(StoredFile::class));
    }

    /**
     * A chain asks its namespace drivers before its default driver: a default driver that claims every entity class
     * no longer claims the bundle entities.
     */
    public function testWinsOverTheDefaultDriverOfAChain(): void
    {
        $catchAll = $this->createStub(MappingDriver::class);
        $catchAll->method('isTransient')->willReturn(false);
        $chain = new MappingDriverChain();
        $chain->setDefaultDriver($catchAll);
        self::assertFalse($chain->isTransient(StoredFile::class));

        $chain->addDriver(new ExcludedEntityNamespaceDriver(), StorageEntityManagerPass::ENTITY_NAMESPACE);

        self::assertTrue($chain->isTransient(StoredFile::class));
        self::assertTrue($chain->isTransient(StorageProfile::class));
        self::assertFalse($chain->isTransient(Note::class), 'other classes still go to the default driver');
    }
}
