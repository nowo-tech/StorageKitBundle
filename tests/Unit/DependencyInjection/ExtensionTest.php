<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\DependencyInjection;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Doctrine\TablePrefixListener;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Reference\FileReferenceListener;
use Nowo\StorageKitBundle\Reference\FileReferences;
use Nowo\StorageKitBundle\Reference\FileUris;
use Nowo\StorageKitBundle\Tests\Support\NullRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ExtensionTest extends TestCase
{
    public function testThePrependNeedsNoOptionalBundle(): void
    {
        $container = new ContainerBuilder();
        (new NowoStorageKitExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    public function testTheSettingsBackedBucketNeedsInstanceSettingsInstalled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['NowoDoctrineEncryptBundle' => 'x', 'SecurityBundle' => 'y']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('which needs nowo-tech/instance-settings-bundle');
        (new NowoStorageKitExtension(false))->load([[]], $container);
    }

    public function testAConfigPinnedBucketDoesNotNeedInstanceSettings(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['NowoDoctrineEncryptBundle' => 'x', 'SecurityBundle' => 'y']);
        (new NowoStorageKitExtension(false))->load([['instance_profile' => 'main', 'profiles' => ['main' => ['bucket' => 'files', 'endpoint' => 'https://s3.example.test', 'access_key' => 'k', 'secret' => 's']]]], $container);

        self::assertFalse($container->getParameter('nowo_storage_kit.instance_settings'));
        self::assertSame('Nowo\StorageKitBundle\Upload\ImageUploader', (string) $container->getAlias('Nowo\StorageKitBundle\Upload\ImageUploaderInterface'));
    }

    public function testANonOrmManagerIsRefused(): void
    {
        $manager  = $this->createStub(ObjectManager::class);
        $registry = new class($manager) extends AbstractManagerRegistry {
            public function __construct(private readonly ObjectManager $manager)
            {
                parent::__construct('x', [], ['default' => 'm'], 'default', 'default', 'Doctrine\Persistence\Proxy');
            }

            protected function getService(string $name): ObjectManager
            {
                return $this->manager;
            }

            protected function resetService(string $name): void
            {
            }
        };

        $this->expectException(LogicException::class);
        (new StorageEntityManager($registry))->manager();
    }

    public function testThePrefixSkipsMissingIndexLists(): void
    {
        $metadata = new ClassMetadata(StorageProfile::class);
        $metadata->setPrimaryTable(['name' => 'storage_kit_profile']);
        // @phpstan-ignore assign.propertyType (a malformed mapping on purpose)
        $metadata->table['indexes'] = 'not a list';
        (new TablePrefixListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class)));

        self::assertSame('app_storage_kit_profile', $metadata->getTableName());
    }

    public function testAListenerWithoutSourcesDoesNothing(): void
    {
        $uris     = new FileUris();
        $listener = new FileReferenceListener(new FileReferences(new StorageEntityManager(new NullRegistry()), $uris), $uris);
        $listener->onFlush(new OnFlushEventArgs($this->createStub(EntityManagerInterface::class)));
        $listener->postFlush();
        $listener->reset();

        self::addToAssertionCount(1);
    }
}
