<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
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
     * Whether a sync to `$target` is queued or running in the database (1.7, read with DBAL: a sync another request
     * started is seen inside a write unit).
     */
    public function hasOpenTo(StorageProfile $target): bool
    {
        return $target->getId() !== null && (int) $this->entityManager->connection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->entityManager->table(StorageSync::class) . ' WHERE to_id = ? AND status IN (?)',
            [$target->getId(), [StorageSync::QUEUED, StorageSync::RUNNING]],
            [Types::INTEGER, ArrayParameterType::STRING],
        ) > 0;
    }

    /**
     * A sync by database id.
     */
    public function find(int $id): ?StorageSync
    {
        return $this->entityManager->manager()->find(StorageSync::class, $id);
    }
}
