<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;

/**
 * The entity manager of the bundle entities (`doctrine.entity_manager`, null = the default one), fetched per call so a
 * reset / closed manager is replaced in worker mode, plus the DBAL connection and the (prefixed) table names for the
 * bounded raw-SQL statements.
 */
final readonly class StorageEntityManager
{
    /**
     * @param string|null $name entity manager name
     */
    public function __construct(
        private ManagerRegistry $registry,
        private ?string $name = null,
    ) {
    }

    /**
     * The entity manager.
     *
     * @throws LogicException when the configured manager is not an ORM entity manager
     */
    public function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManager($this->name);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('NowoStorageKitBundle needs a Doctrine ORM entity manager (doctrine.entity_manager).');
        }

        return $manager;
    }

    /**
     * Its DBAL connection.
     */
    public function connection(): Connection
    {
        return $this->manager()->getConnection();
    }

    /**
     * The table name of a bundle entity, prefix included.
     *
     * @param class-string $class
     */
    public function table(string $class): string
    {
        return $this->manager()->getClassMetadata($class)->getTableName();
    }
}
