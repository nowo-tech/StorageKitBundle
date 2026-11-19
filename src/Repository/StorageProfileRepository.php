<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Repository;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\QueryBuilder;
use Nowo\StorageKitBundle\Doctrine\OwnerLock;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Identifier\ExactIdentifier;
use Nowo\StorageKitBundle\Profile\StorageProfiles;
use Nowo\StorageKitBundle\Scope\StorageScope;

use function array_filter;
use function array_values;

/**
 * Queries of {@see StorageProfile}: an owner's active / target / retired profiles, the rows of config profiles.
 *
 * Scope keys are matched byte for byte (REQ-SEC-006, 1.5.3): the profile ids of a scope are read with an exact SQL
 * expression and re-checked with `===` before any `LIMIT`, and every profile returned is checked again, so the scope
 * `Acme` never reaches the bucket of `acme` on a case-insensitive collation (its Storage and Move pages, its uploads).
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

        return $this->ofScope($profiles, $scope);
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

        return $all ? $profiles : $this->ofScope($profiles, $scope);
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
     * Whether `$profile` is still the owner's profile in `$state` (1.7, read with DBAL, so a change committed by
     * another request is seen inside a write unit): a saved profile still holds that state; a new one (no id) has no
     * database profile of the owner in that state yet. Run it under the owner lock ({@see self::ownerLock()}).
     */
    public function isCurrent(StorageProfile $profile, ?string $scope, string $state): bool
    {
        $connection = $this->entityManager->connection();
        $table      = $this->entityManager->table(StorageProfile::class);
        $id         = $profile->getId();
        if ($id !== null) {
            return $connection->fetchOne('SELECT state FROM ' . $table . ' WHERE id = ?', [$id]) === $state;
        }
        // One statement, re-checked byte for byte: a look-alike scope's profile does not count (REQ-SEC-006).
        $exact = $scope === null ? 'scope IS NULL' : 'scope = ? AND ' . ExactIdentifier::sql($connection->getDatabasePlatform(), 'scope') . ' = ?';
        foreach ($connection->fetchFirstColumn('SELECT scope FROM ' . $table . ' WHERE state = ? AND config_name IS NULL AND ' . $exact, $scope === null ? [$state] : [$state, $scope, $scope]) as $stored) {
            if ($scope === null || ExactIdentifier::same($stored, $scope)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the instance's active row for the `storage` section (`$configName` null) or the row of config profile
     * `$configName` exists (1.7.1, audit UH-SK-2), read with DBAL and, on MySQL / MariaDB, as a locking read
     * (`FOR UPDATE`): a row another request inserted and has not committed yet is waited for, never missed. Run it under
     * the owner lock ({@see StorageEntityManager::exclusively()}) before creating the row: there `GET_LOCK()` alone is
     * not enough when the first use runs inside your open transaction, because it is released before your commit. The
     * rows locked are the instance's few active rows (index `scope, state`), until your transaction ends.
     *
     * The wait is bounded by `conflict_response.owner_lock_timeout` (audit UH2-SK-2), not the 50-second
     * `innodb_lock_wait_timeout` default, because the owner lock is held meanwhile: the session value is lowered for
     * this statement only and restored right after it ({@see OwnerLock::boundedRowLockWait()}). A lock wait timeout
     * (1205) is a retry-safe conflict.
     */
    public function lockInstanceRow(?string $configName): bool
    {
        $connection = $this->entityManager->connection();
        $mysql      = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $sql        = 'SELECT id FROM ' . $this->entityManager->table(StorageProfile::class)
            . ' WHERE scope IS NULL AND state = ? AND ' . ($configName === null ? 'config_name IS NULL' : 'config_name = ?')
            . ($mysql ? ' FOR UPDATE' : '');
        $params = $configName === null ? [StorageProfile::STATE_ACTIVE] : [StorageProfile::STATE_ACTIVE, $configName];

        return OwnerLock::boundedRowLockWait(
            $connection,
            $this->entityManager->ownerLockTimeout(),
            static fn (): bool => $connection->fetchFirstColumn($sql, $params) !== [],
        );
    }

    /**
     * The key of the lock that serializes the writes of one owner's profiles and syncs (1.7,
     * {@see StorageEntityManager::transactional()}): `owner/instance` or `owner/scope/<key>`.
     */
    public static function ownerLock(?string $scope): string
    {
        return 'owner/' . StorageScope::suffix($scope);
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

        return $profile instanceof StorageProfile && $profile->getScope() === $scope ? $profile : null;
    }

    private function whereOwner(QueryBuilder $qb, ?string $scope): void
    {
        if ($scope === null) {
            $qb->andWhere('p.scope IS NULL');

            return;
        }
        // The exact ids first: a LIMIT after a folding `p.scope = :scope` could return a look-alike scope's profile.
        $qb->andWhere('p.scope = :scope')->setParameter('scope', $scope)
            ->andWhere('p.id IN (:exact_scope_ids)')->setParameter('exact_scope_ids', $this->idsOfScope($scope));
    }

    /**
     * The ids of the profiles of exactly `$scope` (a scope has a handful: its active, target and retired buckets).
     *
     * @return list<int> never empty (0 matches no row)
     */
    private function idsOfScope(string $scope): array
    {
        $connection = $this->entityManager->connection();
        $ids        = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT id, scope FROM ' . $this->entityManager->table(StorageProfile::class)
            . ' WHERE scope = ? AND ' . ExactIdentifier::sql($connection->getDatabasePlatform(), 'scope') . ' = ?',
            [$scope, $scope],
        ) as $row) {
            if (ExactIdentifier::same($row['scope'], $scope)) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids === [] ? [0] : $ids;
    }

    /**
     * @param list<StorageProfile> $profiles
     *
     * @return list<StorageProfile> those of exactly `$scope`
     */
    private function ofScope(array $profiles, ?string $scope): array
    {
        return array_values(array_filter($profiles, static fn (StorageProfile $profile): bool => $profile->getScope() === $scope));
    }

    private function query(): QueryBuilder
    {
        return $this->entityManager->manager()->createQueryBuilder()->select('p')->from(StorageProfile::class, 'p');
    }
}
