<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Reference;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\StorageKitBundle\Context\FileContextInterface;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;

/**
 * Helper for {@see FileReferenceSourceInterface::existing()} and
 * {@see FileContextInterface::existing()}: which of a list of ids still exist in an
 * entity's table, in one query. Only ids exactly among the asked ones come back (1.5.3): a host table with a case- or
 * accent-insensitive collation returns `abc` for `ABC`, which does not make `ABC` exist.
 */
final class ExistingIds
{
    /**
     * @param class-string $class
     * @param string $field identifier field (a plain property name)
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public static function of(EntityManagerInterface $entityManager, string $class, string $field, array $ids): array
    {
        if ($ids === [] || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) !== 1) {
            return [];
        }
        $rows = $entityManager->createQueryBuilder()
            ->select('e.' . $field . ' AS id')
            ->from($class, 'e')
            ->andWhere('e.' . $field . ' IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getSingleColumnResult();

        // 1.5.3 (REQ-SEC-006): only the asked ids, byte for byte (a folding host collation answers `abc` for `ABC`).
        return array_values(array_filter(array_map(strval(...), $rows), static fn (string $id): bool => in_array($id, $ids, true)));
    }
}
