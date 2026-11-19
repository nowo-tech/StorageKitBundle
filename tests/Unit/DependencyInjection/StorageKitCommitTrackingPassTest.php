<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\DependencyInjection;

use Nowo\StorageKitBundle\DependencyInjection\Compiler\StorageKitCommitTrackingPass;
use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Nowo\StorageKitBundle\EventListener\StorageKitConflictListener;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.7.1 (audit UH-SK-4): the bundle's commit-tracking middleware wraps the bundle's connection only, and is dropped
 * when transaction-doctrine's commit_detection already counts every commit.
 */
final class StorageKitCommitTrackingPassTest extends TestCase
{
    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(NowoStorageKitExtension::COMMIT_LEDGER_SERVICE, new Definition(CommitLedger::class));
        $container->setDefinition(NowoStorageKitExtension::COMMIT_TRACKING_MIDDLEWARE, (new Definition(CommitTrackingMiddleware::class, [new Reference(NowoStorageKitExtension::COMMIT_LEDGER_SERVICE)]))->addTag('doctrine.middleware'));

        return $container;
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function middlewareTags(ContainerBuilder $container): array
    {
        return $container->getDefinition(NowoStorageKitExtension::COMMIT_TRACKING_MIDDLEWARE)->getTag('doctrine.middleware');
    }

    public function testNothingToDoWhenTrackingIsOff(): void
    {
        $container = new ContainerBuilder();
        (new StorageKitCommitTrackingPass())->process($container);
        self::assertFalse($container->hasDefinition(NowoStorageKitExtension::COMMIT_LEDGER_SERVICE));
    }

    public function testTransactionDoctrinesCommitDetectionReplacesTheBundlesTracking(): void
    {
        $container = $this->container();
        $container->setDefinition(CommitLedger::class, new Definition(CommitLedger::class));
        $container->setDefinition(StorageKitConflictListener::class, (new Definition(StorageKitConflictListener::class, [null, null, new Reference(NowoStorageKitExtension::COMMIT_LEDGER_SERVICE), null]))
            ->addTag('kernel.event_listener', ['event' => 'kernel.exception', 'priority' => -64])
            ->addTag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onRequest', 'priority' => StorageKitConflictListener::REQUEST_PRIORITY]));

        (new StorageKitCommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(NowoStorageKitExtension::COMMIT_TRACKING_MIDDLEWARE));
        self::assertFalse($container->hasDefinition(NowoStorageKitExtension::COMMIT_LEDGER_SERVICE));
        $listener = $container->getDefinition(StorageKitConflictListener::class);
        self::assertNull($listener->getArgument(2));
        self::assertSame([['event' => 'kernel.exception', 'priority' => -64]], $listener->getTag('kernel.event_listener'));

        // The same without the listener (nothing else to rewire).
        $container = $this->container();
        $container->setDefinition(CommitLedger::class, new Definition(CommitLedger::class));
        (new StorageKitCommitTrackingPass())->process($container);
        self::assertFalse($container->hasDefinition(NowoStorageKitExtension::COMMIT_TRACKING_MIDDLEWARE));
    }

    public function testTheMiddlewareWrapsTheConnectionOfTheBundlesManagerOnly(): void
    {
        $container = $this->container();
        $container->setParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER, 'storage');
        $container->setDefinition('doctrine.orm.storage_entity_manager', new Definition('stdClass', [new Reference('doctrine.dbal.files_connection')]));
        (new StorageKitCommitTrackingPass())->process($container);
        self::assertSame([['connection' => 'files']], $this->middlewareTags($container));

        // The host's default manager (doctrine.entity_manager null).
        $container = $this->container();
        $container->setParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER, null);
        $container->setParameter('doctrine.default_entity_manager', 'main');
        $container->setDefinition('doctrine.orm.main_entity_manager', new Definition('stdClass', [new Reference('doctrine.dbal.app_connection')]));
        (new StorageKitCommitTrackingPass())->process($container);
        self::assertSame([['connection' => 'app']], $this->middlewareTags($container));
    }

    public function testTheDefaultConnectionWhenTheManagerCannotBeResolved(): void
    {
        $container = $this->container();
        $container->setParameter('doctrine.default_connection', 'primary');
        (new StorageKitCommitTrackingPass())->process($container);
        self::assertSame([['connection' => 'primary']], $this->middlewareTags($container));

        $container = $this->container();
        $container->setParameter('doctrine.default_entity_manager', null);
        $container->setDefinition('doctrine.orm.default_entity_manager', new Definition('stdClass', ['not a reference']));
        (new StorageKitCommitTrackingPass())->process($container);
        self::assertSame([['connection' => 'default']], $this->middlewareTags($container));
    }
}
