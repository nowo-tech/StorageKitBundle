<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A demo text that holds images: its Markdown body points at stored files as `![alt](storage-file:<uuid>)`
 * (App\Storage\NoteReferenceSource tracks them for the reference-aware purge).
 */
#[ORM\Entity]
#[ORM\Table(name: 'demo_note')]
class Note
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 180)]
        private string $author,
        #[ORM\Column(type: 'text')]
        private string $body,
    ) {
        $this->id        = Uuid::v4()->toRfc4122();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAuthor(): string
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
