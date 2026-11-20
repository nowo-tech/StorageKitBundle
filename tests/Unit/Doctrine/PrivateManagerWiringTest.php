<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\StorageKitBundle\Doctrine\OwnEntityManagerSubscriber;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Doctrine\StorageHostFlushReporter;
use Nowo\StorageKitBundle\Tests\Support\Deprecations;
use Nowo\StorageKitBundle\Tests\Support\NullRegistry;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * 1.8: the host-flush reporter, {@see StorageEntityManager} in private mode and the private-manager boundary. The
 * probe's hits on real pending entities are covered by the database test (PrivateEntityManagerKernelTest).
 */
final class PrivateManagerWiringTest extends TestCase
{
    public function testADisabledReporterNeverLooksAtTheManager(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('getUnitOfWork');

        $reporter = new StorageHostFlushReporter(false);
        $reporter->beforeFlush($manager);
        self::assertFalse($reporter->isEnabled());
    }

    public function testThePrivateManagerIsNeverProbed(): void
    {
        $reporter = new StorageHostFlushReporter(true, new HostFlushProbe());
        self::assertTrue($reporter->isEnabled());

        // The decorator would resolve its source through the registry on any call: none is made.
        $reporter->beforeFlush(new PrivateEntityManager(new NullRegistry(), 'default', null, 'NowoStorageKitBundle'));
        $this->addToAssertionCount(1);
    }

    public function testAProbeThatThrowsNeverBreaksTheWrite(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::once())->method('getUnitOfWork')->willThrowException(new RuntimeException('broken unit of work'));

        $messages = Deprecations::collect(static fn () => (new StorageHostFlushReporter(true))->beforeFlush($manager));
        self::assertSame([], $messages);
        (new StorageHostFlushReporter(true))->reset();
    }

    public function testThePrivateModeOwnsItsManagerAndNeverResetsTheRegistry(): void
    {
        $private = new PrivateEntityManager(new NullRegistry(), 'default', null, 'NowoStorageKitBundle');
        $storage = new StorageEntityManager(new NullRegistry(), null, 5, $private, new StorageHostFlushReporter(true));

        self::assertSame($private, $storage->manager());
        self::assertTrue($storage->ownsManager());
        self::assertTrue($storage->isPrivate());
        // NullRegistry fails on any manager lookup: resetIfClosed() must not touch it in private mode.
        $storage->resetIfClosed();
        self::assertFalse((new StorageEntityManager(new NullRegistry()))->isPrivate());
    }

    public function testADedicatedOrPrivateFlushSkipsTheProbe(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('getUnitOfWork');
        $manager->expects(self::once())->method('flush');
        $private = new PrivateEntityManager(new NullRegistry(), 'default', null, 'NowoStorageKitBundle');

        (new StorageEntityManager(new NullRegistry(), null, 5, $private, new StorageHostFlushReporter(true)))->flush($manager);
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
