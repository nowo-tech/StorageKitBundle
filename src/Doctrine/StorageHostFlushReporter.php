<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\StorageKitBundle\EventListener\RequestBoundaryListener;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Symfony\Contracts\Service\ResetInterface;

/**
 * 1.8 (REQ-ARCH-001 rule 6 deprecation layer): reports the application's pending changes that a flush of the bundle
 * writes while `doctrine.private_entity_manager` is not true and the bundle entities live on the application's default
 * entity manager (2.0 always writes through a private entity manager and no longer flushes them).
 *
 * {@see StorageEntityManager::flush()} calls it right before each flush of the host's manager (the admin pages and
 * console commands: profile save, test and switch, bucket moves, the purge, the retired-profile cleanup, the
 * adoption). For each entity class outside `Nowo\StorageKitBundle\Entity` with pending work in that manager, the
 * host-flush probe of nowo-tech/transaction-doctrine ({@see HostFlushProbe}) triggers one `E_USER_DEPRECATED` per class
 * and unit of work: "Your pending changes to App\X were flushed by NowoStorageKitBundle; 2.0 no longer does." The
 * bundle never changes an application entity, so every class reported is the application's own work.
 *
 * Disabled (nothing is inspected) with `private_entity_manager: true`: the bundle then writes through the private
 * manager, which holds no application change. Unset and `false` (removed in 2.0) both run it. A
 * {@see PrivateEntityManager} is never inspected; neither is a dedicated manager (`doctrine.entity_manager` naming one
 * other than the default: {@see StorageEntityManager::flush()} does not call it then).
 *
 * Read-only and fail-safe: it never flushes, clears, closes or resets a manager (REQ-RUNTIME-003), and a failing
 * inspection never breaks the write (transaction-doctrine 1.9.1 catches it inside the probe). The probe is the shared `nowo_transaction_doctrine.host_flush_probe` when
 * the application registers NowoTransactionDoctrineBundle, else one of its own. Its "already reported" classes are reset at every unit-of-work boundary (main request start and
 * `kernel.terminate`, every Messenger message, the outermost console command: {@see RequestBoundaryListener}) and at
 * `kernel.reset`, so a FrankenPHP worker with the kernel reset off starts every unit clean.
 *
 * @internal registered by the bundle; not an extension point
 */
final readonly class StorageHostFlushReporter implements ResetInterface
{
    /** The bundle named in the deprecation. */
    public const BUNDLE_NAME = 'NowoStorageKitBundle';

    /** The bundle's entity namespace: its classes are never reported. */
    public const OWN_NAMESPACES = ['Nowo\\StorageKitBundle\\Entity'];

    private HostFlushProbe $probe;

    /**
     * @param bool $enabled true unless `doctrine.private_entity_manager` is true
     * @param HostFlushProbe|null $probe transaction-doctrine's shared probe (null: one of its own)
     */
    public function __construct(
        private bool $enabled = false,
        ?HostFlushProbe $probe = null,
    ) {
        $this->probe = $probe ?? new HostFlushProbe();
    }

    /**
     * Whether the probe runs at all (`doctrine.private_entity_manager` not true).
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Right before the bundle flushes the host's `$entityManager`: reports the application's pending changes it writes.
     */
    public function beforeFlush(EntityManagerInterface $entityManager): void
    {
        if (!$this->enabled || $entityManager instanceof PrivateEntityManager) {
            return;
        }

        // Never throws since transaction-doctrine 1.9.1 (audit XA-3): a failed inspection reports nothing and the
        // flush that follows runs as it did before 1.8.
        $this->probe->beforeHostFlush($entityManager, self::BUNDLE_NAME, self::OWN_NAMESPACES);
    }

    /**
     * A new unit of work (request, message, command) starts: nothing was reported yet.
     */
    public function reset(): void
    {
        $this->probe->reset();
    }
}
