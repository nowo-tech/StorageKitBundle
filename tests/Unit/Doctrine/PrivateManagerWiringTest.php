<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\StorageKitBundle\Doctrine\OwnEntityManagerSubscriber;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Tests\Support\NullRegistry;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Contracts\Service\ResetInterface;

/**
 * {@see StorageEntityManager} in private mode (the only mode since 2.0, besides a dedicated manager) and the
 * private-manager boundary.
 */
final class PrivateManagerWiringTest extends TestCase
{
    public function testThePrivateModeOwnsItsManagerAndNeverResetsTheRegistry(): void
    {
        $private = new PrivateEntityManager(new NullRegistry(), 'default', null, 'NowoStorageKitBundle');
        // The 1.8 host-flush reporter argument stays in the signature, ignored (deprecated since 2.0, removed in 3.0).
        $storage = new StorageEntityManager(new NullRegistry(), null, 5, $private, new stdClass());

        self::assertSame($private, $storage->manager());
        self::assertTrue($storage->ownsManager());
        self::assertTrue($storage->isPrivate());
        // NullRegistry fails on any manager lookup: resetIfClosed() must not touch it in private mode.
        $storage->resetIfClosed();
        self::assertFalse((new StorageEntityManager(new NullRegistry()))->isPrivate());
    }

    public function testAFlushFlushesOnlyTheGivenManager(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('getUnitOfWork');
        $manager->expects(self::once())->method('flush');
        $private = new PrivateEntityManager(new NullRegistry(), 'default', null, 'NowoStorageKitBundle');

        (new StorageEntityManager(new NullRegistry(), null, 5, $private))->flush($manager);
    }

    /**
     * 2.0: no host mode. Without the private manager, only a dedicated manager (a name other than the default one) is
     * ever used; the application's default manager never is, unset or named.
     */
    public function testWithoutThePrivateManagerTheDefaultManagerIsNeverUsed(): void
    {
        foreach ([null, 'default'] as $name) {
            $storage = new StorageEntityManager(new NullRegistry(), $name);
            self::assertFalse($storage->ownsManager());
            try {
                $storage->manager();
                self::fail('LogicException expected');
            } catch (LogicException $e) {
                self::assertStringContainsString('never reads or writes its entities through your default entity manager', $e->getMessage());
            }
        }
        $dedicated = new StorageEntityManager(new NullRegistry(), 'storage_kit');
        self::assertTrue($dedicated->ownsManager());
        self::assertFalse($dedicated->isPrivate());
    }

    /**
     * A dedicated manager (the DDD option) that a failed write closed is replaced through the registry; an open one is
     * left alone.
     */
    public function testAClosedDedicatedManagerIsReplacedAndAnOpenOneIsNot(): void
    {
        foreach ([[false, 1], [true, 0]] as [$open, $resets]) {
            $manager = $this->createStub(EntityManagerInterface::class);
            $manager->method('isOpen')->willReturn($open);
            $registry = $this->createMock(ManagerRegistry::class);
            $registry->method('getManager')->with('storage_kit')->willReturn($manager);
            $registry->expects(self::exactly($resets))->method('resetManager')->with('storage_kit');

            (new StorageEntityManager($registry, 'storage_kit'))->resetIfClosed();
        }
    }

    public function testTheBoundaryDropsThePrivateManagerAndNeverTheRegistrysManagers(): void
    {
        $private = new class implements ResetInterface {
            public int $resets = 0;

            public function reset(): void
            {
                ++$this->resets;
            }
        };
        // NullRegistry fails on any manager lookup: recover() and clear() must not use it.
        $subscriber = new OwnEntityManagerSubscriber(new NullRegistry(), 'default', null, $private);
        $subscriber->start();
        $subscriber->clear();

        self::assertSame(2, $private->resets);
    }
}
