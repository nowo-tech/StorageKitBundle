<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A text of the tests that holds images (a note on a board).
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_note')]
class Note
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    public function __construct(
        #[ORM\Column(length: 64)]
        private string $board,
        #[ORM\Column(type: 'text')]
        private string $body = '',
    ) {
        $this->id = Uuid::v4()->toRfc4122();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBoard(): string
    {
        return $this->board;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }
}
