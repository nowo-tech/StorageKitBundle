<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Repository;

use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StorageSync;

/**
 * Queries of {@see StorageSync}.
 */
final readonly class StorageSyncRepository
{
    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private StorageEntityManager $entityManager,
    ) {
    }

    /**
     * The sync of a pair of profiles (one per pair: a new run resumes it).
     */
    public function forPair(StorageProfile $from, StorageProfile $to): ?StorageSync
    {
        return $this->entityManager->manager()->getRepository(StorageSync::class)->findOneBy(['from' => $from, 'to' => $to], ['id' => 'DESC']);
    }

    /**
     * A sync by database id.
     */
    public function find(int $id): ?StorageSync
    {
        return $this->entityManager->manager()->find(StorageSync::class, $id);
    }
}
