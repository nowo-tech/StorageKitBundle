<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Repository;

use Doctrine\ORM\QueryBuilder;
use Nowo\StorageKitBundle\Doctrine\StorageEntityManager;
use Nowo\StorageKitBundle\Entity\StorageProfile;
use Nowo\StorageKitBundle\Entity\StoredFile;
use Nowo\StorageKitBundle\Entity\StoredFileReference;
use Nowo\StorageKitBundle\PersonalData\UploaderIdentity;

use function array_slice;
use function array_unique;
use function array_values;
use function assert;
use function count;
use function is_array;
use function is_scalar;

/**
 * Queries of {@see StoredFile}. Every list is bounded.
 */
final readonly class StoredFileRepository
{
    /** Files resolved by one {@see self::byUuids()} call at most (a text's images). */
    public const UUIDS_LIMIT = 200;

    /**
     * Dependencies and configuration.
     */
    public function __construct(
        private StorageEntityManager $entityManager,
    ) {
    }

    /**
     * A file by public id.
     */
    public function findOneByUuid(string $uuid): ?StoredFile
    {
        return $this->entityManager->manager()->getRepository(StoredFile::class)->findOneBy(['uuid' => $uuid]);
    }

    /**
     * Files by public id with their profile, in one query (the first {@see self::UUIDS_LIMIT} ids).
     *
     * @param list<string> $uuids
     *
     * @return array<string, StoredFile> uuid => file
     */
    public function byUuids(array $uuids): array
    {
        $uuids = array_slice(array_values(array_unique($uuids)), 0, self::UUIDS_LIMIT);
        if ($uuids === []) {
            return [];
        }
        $files = [];
        foreach ($this->entityManager->manager()->createQueryBuilder()
            ->select('f', 'p')
            ->from(StoredFile::class, 'f')
            ->innerJoin('f.profile', 'p')
            ->andWhere('f.uuid IN (:uuids)')
            ->setParameter('uuids', $uuids)
            ->getQuery()
            ->getResult() as $file) {
            assert($file instanceof StoredFile);
            $files[$file->getUuid()] = $file;
        }

        return $files;
    }

    /**
     * Bytes stored (originals and variants) for usage owners.
     *
     * @param list<string> $owners
     */
    public function bytesFor(array $owners): int
    {
        if ($owners === []) {
            return 0;
        }

        return (int) $this->entityManager->manager()->createQueryBuilder()
            ->select('COALESCE(SUM(f.totalBytes), 0)')
            ->from(StoredFile::class, 'f')
            ->andWhere('f.owner IN (:owners)')
            ->setParameter('owners', array_values(array_unique($owners)))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * A page of the files a user uploaded (keyset by id), each with whether a text points at it.
     *
     * Without `$userKey`, the files whose stored user identifier is `$userIdentifier`. With it, the files stored with
     * that stable key plus the files stored without a key (before 1.2.0, or by a user without one) under
     * `$userIdentifier`; a file stored with another key is never matched by identifier (the identifier may have been
     * given to someone else since).
     *
     * Since 1.5.2 every row the SQL returns is matched again with `===` ({@see UploaderIdentity::matches()}): on a
     * case-, accent- or trailing-space-insensitive collation `Alice`, `álice` and `alice ` are not `alice`. A page may
     * then hold fewer than `$limit` files; it is empty only when no file after `$afterId` matches.
     *
     * @return list<array{file: StoredFile, referenced: bool}>
     */
    public function uploadedBy(string $userIdentifier, int $afterId, int $limit, ?string $userKey = null): array
    {
        $files = [];
        do {
            $rows = $this->uploaderQuery('f', $userIdentifier, $afterId, $limit, $userKey)->getQuery()->getResult();
            foreach ($rows as $row) {
                assert(is_array($row) && $row[0] instanceof StoredFile);
                $file    = $row[0];
                $afterId = (int) $file->getId();
                if (UploaderIdentity::matches($file->getUploadedBy(), $file->getUploadedByKey(), $userIdentifier, $userKey)) {
                    $files[] = ['file' => $file, 'referenced' => (int) $row['refs'] > 0];
                }
            }
            // A full page of look-alikes only: read on, an empty page would end the caller's walk.
        } while ($files === [] && count($rows) === $limit);

        return $files;
    }

    /**
     * The same page as {@see self::uploadedBy()} (same match, keyset, order and `===` re-check), as identifiers only
     * (1.5): no entity is loaded into the entity manager, for the DBAL erasure.
     *
     * @return list<array{id: int, referenced: bool}>
     */
    public function uploadedIdsBy(string $userIdentifier, int $afterId, int $limit, ?string $userKey = null): array
    {
        $files = [];
        do {
            $rows = $this->uploaderQuery('f.id AS id, f.uploadedBy AS uploadedBy, f.uploadedByKey AS uploadedByKey', $userIdentifier, $afterId, $limit, $userKey)->getQuery()->getScalarResult();
            foreach ($rows as $row) {
                assert(is_array($row));
                $afterId = (int) $row['id'];
                if (UploaderIdentity::matches($this->nullableString($row['uploadedBy']), $this->nullableString($row['uploadedByKey']), $userIdentifier, $userKey)) {
                    $files[] = ['id' => $afterId, 'referenced' => (int) $row['refs'] > 0];
                }
            }
        } while ($files === [] && count($rows) === $limit);

        return $files;
    }

    /**
     * The SQL pre-filter of {@see self::uploadedBy()} / {@see self::uploadedIdsBy()} (re-checked in PHP).
     */
    private function uploaderQuery(string $select, string $userIdentifier, int $afterId, int $limit, ?string $userKey): QueryBuilder
    {
        $query = $this->entityManager->manager()->createQueryBuilder()
            ->select($select)
            ->addSelect('(SELECT COUNT(r.id) FROM ' . StoredFileReference::class . ' r WHERE r.file = f) AS refs')
            ->from(StoredFile::class, 'f')
            ->andWhere('f.id > :after')
            ->setParameter('user', $userIdentifier)
            ->setParameter('after', $afterId)
            ->orderBy('f.id', 'ASC')
            ->setMaxResults($limit);
        if ($userKey === null || $userKey === '') {
            $query->andWhere('f.uploadedBy = :user');
        } else {
            $query->andWhere('f.uploadedByKey = :key OR (f.uploadedByKey IS NULL AND f.uploadedBy = :user)')
                ->setParameter('key', $userKey);
        }

        return $query;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * What a profile holds (Storage page usage).
     *
     * @return array{files: int, bytes: int}
     */
    public function usageOf(StorageProfile $profile): array
    {
        /** @var array{files: int|string, bytes: int|string|null} $row */
        $row = $this->entityManager->manager()->createQueryBuilder()
            ->select('COUNT(f.id) AS files', 'COALESCE(SUM(f.totalBytes), 0) AS bytes')
            ->from(StoredFile::class, 'f')
            ->andWhere('f.profile = :profile')
            ->setParameter('profile', $profile)
            ->getQuery()
            ->getSingleResult();

        return ['files' => (int) $row['files'], 'bytes' => (int) $row['bytes']];
    }
}
