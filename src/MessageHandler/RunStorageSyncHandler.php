<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\MessageHandler;

use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Message\RunStorageSyncMessage;
use Nowo\StorageKitBundle\Repository\StorageSyncRepository;
use Nowo\StorageKitBundle\Sync\StorageSyncer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs a queued sync for at most `sync.slice_seconds`, then queues the next slice (the worker keeps serving its other
 * jobs; a restarted worker picks the sync up where it was). A sync that is not queued (cancelled, done, held by another
 * runner) is left alone, so a duplicated message is harmless.
 *
 * Registration (REQ-MSG-001): `#[AsMessageHandler]` for hosts that autoconfigure it, and the bundle's own service
 * definition keeps its explicit `messenger.message_handler` tag (it is not autoconfigured, so the handler is registered
 * once). Retry and failure: a file that cannot be copied does not throw, it stops the sync as `failed` with the file
 * and a scrubbed reason (resume it from the Move page or `nowo:storage-kit:sync`). Only an infrastructure error (the
 * database or the lock store unreachable) throws: the transport's retry strategy then redelivers the message (Symfony
 * default: 3 retries, delay ×2), and a redelivered message that finds the sync no longer queued leaves it alone; the
 * sync stays `running` until `sync.stale_seconds` pass, then `nowo:storage-kit:sync` takes it over (or *Stop copying*
 * and *Resume copying* on the Move page). Messages
 * that exhaust their retries go to the host's failure transport, if any; re-running one is harmless.
 */
#[AsMessageHandler]
final readonly class RunStorageSyncHandler
{
    /** Default `sync.slice_seconds`. */
    public const SLICE_SECONDS = 240;

    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private StorageSyncRepository $syncs,
        private StorageSyncer $syncer,
        private MessageBusInterface $bus,
        private int $slice = self::SLICE_SECONDS,
    ) {
    }

    /**
     * Handles the message.
     */
    public function __invoke(RunStorageSyncMessage $message): void
    {
        $sync = $this->syncs->find($message->syncId);
        if (!$sync instanceof StorageSync || $sync->getStatus() !== StorageSync::QUEUED) {
            return;
        }
        // Still open after a run of its own: the slice ended (queued) — the next message continues it.
        if ($this->syncer->run($sync, $this->slice) && $sync->isOpen()) {
            $this->bus->dispatch(new RunStorageSyncMessage($message->syncId));
        }
    }
}
