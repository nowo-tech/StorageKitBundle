<?php

declare(strict_types=1);

namespace App\Storage;

use App\Entity\Note;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\StorageKitBundle\Reference\ExistingIds;
use Nowo\StorageKitBundle\Reference\FileReferenceSourceInterface;

use function assert;

/**
 * The bodies of the demo notes point at stored files (REQ-MEDIA-005): saving or deleting a note updates its
 * references; a file no note shows any more is purged after the retention.
 */
final readonly class NoteReferenceSource implements FileReferenceSourceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
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

    public function context(object $entity): array
    {
        return [DemoBoardContext::TYPE, DemoBoardContext::ID];
    }

    public function existing(array $ids): array
    {
        return ExistingIds::of($this->entityManager, Note::class, 'id', $ids);
    }
}
