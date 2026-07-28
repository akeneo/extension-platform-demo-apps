<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
class Product
{
    #[ORM\Id]
    #[ORM\Column(length: 255)]
    private string $identifier;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $label = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $imageFilenames = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $categories = null;

    #[ORM\Column]
    private bool $enabled = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $parent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $parentLabel = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $variationLabel = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?float $completeness = null;

    #[ORM\Column(nullable: true)]
    private ?int $stock = null;

    #[ORM\Column(nullable: true)]
    private ?float $price = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    public function __construct(string $identifier)
    {
        $this->identifier = $identifier;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getLabel(): ?array
    {
        return $this->label;
    }

    public function setLabel(?array $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getLabelForLocale(string $locale = 'en_US'): string
    {
        return $this->label[$locale] ?? $this->identifier;
    }

    public function getImageFilenames(): ?array
    {
        return $this->imageFilenames;
    }

    public function setImageFilenames(?array $imageFilenames): static
    {
        $this->imageFilenames = $imageFilenames;
        return $this;
    }

    public function getCategories(): ?array
    {
        return $this->categories;
    }

    public function setCategories(?array $categories): static
    {
        $this->categories = $categories;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getParent(): ?string
    {
        return $this->parent;
    }

    public function setParent(?string $parent): static
    {
        $this->parent = $parent;
        return $this;
    }

    public function getParentLabel(): ?string
    {
        return $this->parentLabel;
    }

    public function setParentLabel(?string $parentLabel): static
    {
        $this->parentLabel = $parentLabel;
        return $this;
    }

    public function getVariationLabel(): ?string
    {
        return $this->variationLabel;
    }

    public function setVariationLabel(?string $variationLabel): static
    {
        $this->variationLabel = $variationLabel;
        return $this;
    }

    public function isVariant(): bool
    {
        return $this->parent !== null;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getCompleteness(): ?float
    {
        return $this->completeness;
    }

    public function setCompleteness(?float $completeness): static
    {
        $this->completeness = $completeness;
        return $this;
    }

    public function getStock(): ?int
    {
        return $this->stock;
    }

    public function setStock(?int $stock): static
    {
        $this->stock = $stock;
        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(?float $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getSyncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(?\DateTimeImmutable $syncedAt): static
    {
        $this->syncedAt = $syncedAt;
        return $this;
    }
}
