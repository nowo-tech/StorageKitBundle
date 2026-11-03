<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\StorageKitBundle\Reference\FileReferences;
use Nowo\StorageKitBundle\Reference\FileReferenceSourceInterface;

/**
 * One text that points at a stored file — by source type and id ({@see FileReferenceSourceInterface}).
 * Written by {@see FileReferences} when the text is saved; a file with no row is an
 * orphan (REQ-MEDIA-005 reference-aware purge).
 */
#[ORM\Entity]
#[ORM\Table(name: 'storage_kit_file_reference')]
#[ORM\UniqueConstraint(name: 'uniq_storage_kit_file_reference', columns: ['file_id', 'source_type', 'source_id'])]
#[ORM\Index(name: 'idx_storage_kit_file_reference_source', columns: ['source_type', 'source_id'])]
class StoredFileReference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    /**
     * A text (source type and id) that points at a file.
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: StoredFile::class)]
        #[ORM\JoinColumn(name: 'file_id', nullable: false, onDelete: 'CASCADE')]
        private StoredFile $file,
        #[ORM\Column(name: 'source_type', length: 16)]
        private string $sourceType,
        #[ORM\Column(name: 'source_id', length: 64)]
        private string $sourceId,
    ) {
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * Database id.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * The file pointed at.
     */
    public function getFile(): StoredFile
    {
        return $this->file;
    }

    /**
     * Source type of the text.
     */
    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    /**
     * Source id of the text.
     */
    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    /**
     * When the text started pointing at the file.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
