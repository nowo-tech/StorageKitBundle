<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\DependencyInjection\Compiler;

use Nowo\StorageKitBundle\DependencyInjection\NowoStorageKitExtension;
use Nowo\StorageKitBundle\EventListener\StorageKitConflictListener;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;
use function preg_match;
use function sprintf;

/**
 * 1.7.1 (REQ-RUNTIME-003 rule 6, audit UH-SK-4): scopes the commit tracking of the conflict answer.
 *
 * - nowo-tech/transaction-doctrine's bundle registered with `commit_detection` (its {@see CommitLedger} service
 *   and its `doctrine.middleware`-tagged commit-tracking middleware exist): its middleware already counts every commit and its request mark is read by the conflict listener, so the
 *   bundle's middleware and ledger are removed and the listener takes no mark of its own. No connection is wrapped
 *   twice.
 * - Otherwise (`conflict_response.commit_tracking: bundle`): the middleware wraps only the connection of the bundle's
 *   entity manager (`doctrine.entity_manager`, else the default one), never the host's other connections.
 * - `commit_tracking: off`: the extension registered nothing; this pass does nothing.
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0).
 *
 * @internal
 */
final class StorageKitCommitTrackingPass implements CompilerPassInterface
{
    /** transaction-doctrine's commit-tracking middleware (registered by its bundle with `commit_detection`). */
    public const TRANSACTION_DOCTRINE_MIDDLEWARE = 'nowo_transaction_doctrine.commit_tracking_middleware';

    /**
     * Drops or scopes the bundle's commit-tracking middleware.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(NowoStorageKitExtension::COMMIT_TRACKING_MIDDLEWARE)) {
            return;
        }
        if ($this->transactionDoctrineTracks($container)) {
            $container->removeDefinition(NowoStorageKitExtension::COMMIT_TRACKING_MIDDLEWARE);
            $container->removeDefinition(NowoStorageKitExtension::COMMIT_LEDGER_SERVICE);
            if ($container->hasDefinition(StorageKitConflictListener::class)) {
                $listener = $container->getDefinition(StorageKitConflictListener::class)->replaceArgument(2, null);
                $tags     = $listener->getTag('kernel.event_listener');
                $listener->clearTag('kernel.event_listener');
                foreach ($tags as $tag) {
                    if (($tag['method'] ?? null) !== 'onRequest') {
                        $listener->addTag('kernel.event_listener', $tag);
                    }
                }
            }

            return;
        }
        $container->getDefinition(NowoStorageKitExtension::COMMIT_TRACKING_MIDDLEWARE)
            ->clearTag('doctrine.middleware')
            ->addTag('doctrine.middleware', ['connection' => $this->bundleConnection($container)]);
    }

    /**
     * Whether transaction-doctrine's bundle counts commits itself (UH2-SK-6): its {@see CommitLedger} service and its
     * commit-tracking middleware, tagged `doctrine.middleware`. A stray CommitLedger service alone (registered by your
     * code, with no middleware feeding it) does not count, so the bundle keeps its own tracking.
     */
    private function transactionDoctrineTracks(ContainerBuilder $container): bool
    {
        return $container->has(CommitLedger::class)
            && $container->hasDefinition(self::TRANSACTION_DOCTRINE_MIDDLEWARE)
            && $container->getDefinition(self::TRANSACTION_DOCTRINE_MIDDLEWARE)->hasTag('doctrine.middleware');
    }

    /**
     * The connection name of the bundle's entity manager; the default connection when it cannot be resolved.
     */
    private function bundleConnection(ContainerBuilder $container): string
    {
        $manager = $container->hasParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER) ? $container->getParameter(NowoStorageKitExtension::ENTITY_MANAGER_PARAMETER) : null;
        if (!is_string($manager) || $manager === '') {
            $manager = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : 'default';
        }
        $id       = sprintf('doctrine.orm.%s_entity_manager', is_string($manager) ? $manager : 'default');
        $argument = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        if ($argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1) {
            return $match[1];
        }
        $default = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;

        return is_string($default) ? $default : 'default';
    }
}
