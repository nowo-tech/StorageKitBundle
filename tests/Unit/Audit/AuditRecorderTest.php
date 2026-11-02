<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Unit\Audit;

use Nowo\StorageKitBundle\Audit\AuditRecorder;
use Nowo\StorageKitBundle\Audit\StorageAuditEvent;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AuditRecorderTest extends TestCase
{
    public function testEventsCarryTheActorIdentifier(): void
    {
        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(StorageAuditEvent::class, static function (StorageAuditEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $recorder = new AuditRecorder($dispatcher);
        $recorder->record(StorageAuditEvent::PROFILE_SAVED, 'acme', new InMemoryUser('alice', null), ['fields' => 'bucket']);
        $recorder->record(StorageAuditEvent::FILES_PURGED, null, 'cron', []);
        $recorder->record(StorageAuditEvent::FILES_PURGED, null, new stdClass(), []);
        (new AuditRecorder())->record(StorageAuditEvent::FILES_PURGED, null, null);

        self::assertCount(3, $events);
        self::assertSame(['profile_saved', 'acme', 'alice', ['fields' => 'bucket']], [$events[0]->action, $events[0]->scope, $events[0]->actor, $events[0]->details]);
        self::assertSame('cron', $events[1]->actor);
        self::assertNull($events[2]->actor);
    }
}
