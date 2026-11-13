<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Doctrine;

use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Persistence\Mapping\MappingException;
use Nowo\StorageKitBundle\DependencyInjection\Compiler\StorageEntityManagerPass;

use function sprintf;

/**
 * Metadata driver that maps nothing: registered for the bundle entity namespace on every entity manager other than
 * `doctrine.entity_manager` by {@see StorageEntityManagerPass}.
 *
 * A {@see MappingDriverChain} asks its namespace drivers before its default driver, so this driver makes the bundle
 * entities transient on those managers even when their chain has a default driver that claims every class carrying
 * `#[ORM\Entity]` (DoctrineBundle 3.4 `auto_discover_entities`, enabled by default on a manager without `mappings` and
 * `auto_mapping`). `ManagerRegistry::getManagerForClass()` then answers the configured manager on DoctrineBundle 2.x
 * and 3.x alike. Stateless (worker-safe).
 */
final class ExcludedEntityNamespaceDriver implements MappingDriver
{
    /**
     * Never maps a class on this manager.
     *
     * @throws MappingException always
     */
    public function loadMetadataForClass(string $className, ClassMetadata $metadata): void
    {
        throw new MappingException(sprintf('Class "%s" is not mapped on this entity manager (nowo_storage_kit.doctrine.entity_manager maps it on another one).', $className));
    }

    /**
     * No class is mapped by this driver.
     *
     * @return list<class-string>
     */
    public function getAllClassNames(): array
    {
        return [];
    }

    /**
     * Every class of the namespace is transient on this manager.
     */
    public function isTransient(string $className): bool
    {
        return true;
    }
}
