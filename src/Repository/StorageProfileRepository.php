<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Repository;

use Doctrine\ORM\QueryBuilder;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Profile\StorageProfiles;

/**
 * Queries of {@see StorageProfile}: an owner's active / target / retired profiles, the rows of config profiles.
 */
final readonly class StorageProfileRepository
{
    /** Retired profiles listed on the Move page at most. */
    public const RETIRED_LIMIT = 20;

    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private StorageEntityManager $entityManager,
    ) {
    }

    /**
     * The active database profile of an owner (scope null = the instance).
     */
    public function activeOf(?string $scope): ?StorageProfile
    {
        return $this->ofOwner($scope, StorageProfile::STATE_ACTIVE);
    }

    /**
     * The bucket an owner is moving to.
     */
    public function targetOf(?string $scope): ?StorageProfile
    {
        return $this->ofOwner($scope, StorageProfile::STATE_TARGET);
    }

    /**
     * The buckets an owner moved away from, newest first (at most {@see self::RETIRED_LIMIT}).
     *
     * @return list<StorageProfile>
     */
    public function retiredOf(?string $scope): array
    {
        $qb = $this->query()
            ->andWhere('p.state = :state')
            ->setParameter('state', StorageProfile::STATE_RETIRED)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(self::RETIRED_LIMIT);
        $this->whereOwner($qb, $scope);

        /** @var list<StorageProfile> $profiles */
        $profiles = $qb->getQuery()->getResult();

        return $profiles;
    }

    /**
     * Retired profiles no stored file points at any more: of one owner, or of every owner with `$all`.
     *
     * @return list<StorageProfile>
     */
    public function retiredUnreferenced(?string $scope, bool $all = false): array
    {
        $qb = $this->query()
            ->andWhere('p.state = :state')
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . StoredFile::class . ' f WHERE f.profile = p)')
            ->setParameter('state', StorageProfile::STATE_RETIRED)
            ->orderBy('p.id', 'ASC');
        if (!$all) {
            $this->whereOwner($qb, $scope);
        }

        /** @var list<StorageProfile> $profiles */
        $profiles = $qb->getQuery()->getResult();

        return $profiles;
    }

    /**
     * The row that names a config profile (created by {@see StorageProfiles}).
     */
    public function configRow(string $name): ?StorageProfile
    {
        /** @var StorageProfile|null $profile */
        $profile = $this->query()
            ->andWhere('p.configName = :name')
            ->setParameter('name', $name)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $profile;
    }

    /**
     * A profile by public id.
     */
    public function findOneByUuid(string $uuid): ?StorageProfile
    {
        return $this->entityManager->manager()->getRepository(StorageProfile::class)->findOneBy(['uuid' => $uuid]);
    }

    private function ofOwner(?string $scope, string $state): ?StorageProfile
    {
        $qb = $this->query()
            ->andWhere('p.state = :state')
            ->andWhere('p.configName IS NULL')
            ->setParameter('state', $state)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(1);
        $this->whereOwner($qb, $scope);

        /** @var StorageProfile|null $profile */
        $profile = $qb->getQuery()->getOneOrNullResult();

        return $profile;
    }

    private function whereOwner(QueryBuilder $qb, ?string $scope): void
    {
        if ($scope === null) {
            $qb->andWhere('p.scope IS NULL');

            return;
        }
        $qb->andWhere('p.scope = :scope')->setParameter('scope', $scope);
    }

    private function query(): QueryBuilder
    {
        return $this->entityManager->manager()->createQueryBuilder()->select('p')->from(StorageProfile::class, 'p');
    }
}
