<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures;

use Nowo\StorageKitBundle\Audit\StorageAuditEvent;

/**
 * Collects the audit events of a test.
 */
final class AuditLog
{
    /** @var list<StorageAuditEvent> */
    public array $events = [];

    public function __invoke(StorageAuditEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return array_map(static fn (StorageAuditEvent $event): string => $event->action, $this->events);
    }
}
