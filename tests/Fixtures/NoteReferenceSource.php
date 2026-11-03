<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\StorageKitBundle\Reference\ExistingIds;
use Nowo\StorageKitBundle\Reference\FileReferenceSourceInterface;
use Nowo\StorageKitBundle\Tests\Fixtures\Entity\Note;

use function assert;

/**
 * Reference source of the tests: the body of a {@see Note} (context: its board).
 */
final readonly class NoteReferenceSource implements FileReferenceSourceInterface
{
    public function __construct(
        private ManagerRegistry $doctrine,
    ) {
    }

    public function type(): string
    {
        return 'note';
    }

    public function entityClass(): string
    {
        return Note::class;
    }

    public function field(): string
    {
        return 'body';
    }

    public function id(object $entity): string
    {
        assert($entity instanceof Note);

        return $entity->getId();
    }

    public function text(object $entity): string
    {
        assert($entity instanceof Note);

        return $entity->getBody();
    }

    public function context(object $entity): ?array
    {
        assert($entity instanceof Note);

        return $entity->getBoard() === 'none' ? null : ['board', $entity->getBoard()];
    }

    public function existing(array $ids): array
    {
        $manager = $this->doctrine->getManager();
        assert($manager instanceof EntityManagerInterface);

        return ExistingIds::of($manager, Note::class, 'id', $ids);
    }
}
