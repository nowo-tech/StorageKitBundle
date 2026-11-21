<?php

declare(strict_types=1);

namespace Nowo\StorageKitBundle\Tests\Fixtures\HostRelations;

use Doctrine\ORM\Mapping as ORM;
use Nowo\StorageKitBundle\Entity\StoredFile;

/**
 * An application entity with a Doctrine relation to a bundle entity (a product with an image), mapped on the
 * application's manager only by the tests that need it (lesson W1-2: relation-mapped host entities).
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_product')]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: StoredFile::class)]
    #[ORM\JoinColumn(name: 'image_id', nullable: true)]
    private ?StoredFile $image = null;

    public function __construct(
        #[ORM\Column(length: 64)]
        private string $name,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function getImage(): ?StoredFile
    {
        return $this->image;
    }

    public function setImage(?StoredFile $image): void
    {
        $this->image = $image;
    }
}
