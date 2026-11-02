<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Audit;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function is_string;

/**
 * Records a mutation: dispatches a {@see StorageAuditEvent} and writes an `info` line (action, scope, actor, details —
 * never a secret).
 */
final readonly class AuditRecorder
{
    /**
     * Event dispatcher (null = log only) and logger.
     */
    public function __construct(
        private ?EventDispatcherInterface $dispatcher = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Records `$action`.
     *
     * @param object|string|null $actor security user, its identifier, or null
     * @param array<string, scalar|null> $details
     */
    public function record(string $action, ?string $scope, object|string|null $actor, array $details = []): void
    {
        $identifier = $actor instanceof UserInterface ? $actor->getUserIdentifier() : (is_string($actor) ? $actor : null);
        $this->dispatcher?->dispatch(new StorageAuditEvent($action, $scope, $identifier, $details));
        $this->logger->info('Storage kit: {action}', ['action' => $action, 'scope' => $scope, 'actor' => $identifier] + $details);
    }
}
