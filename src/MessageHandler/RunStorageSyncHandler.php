<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\MessageHandler;

use Nowo\StorageKitBundle\Entity\StorageSync;
use Nowo\StorageKitBundle\Message\RunStorageSyncMessage;
use Nowo\StorageKitBundle\Repository\StorageSyncRepository;
use Nowo\StorageKitBundle\Sync\StorageSyncer;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs a queued sync for at most `sync.slice_seconds`, then queues the next slice (the worker keeps serving its other
 * jobs; a restarted worker picks the sync up where it was). A sync that is not queued (cancelled, done, held by another
 * runner) is left alone, so a duplicated message is harmless.
 */
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
