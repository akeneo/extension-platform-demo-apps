<?php

namespace App\Entity;

use App\Repository\SyncAttemptRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SyncAttemptRepository::class)]
class SyncAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $type;

    #[ORM\Column(length: 16)]
    private string $status = 'queued';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $input;

    #[ORM\Column(nullable: true)]
    private ?int $count = null;

    #[ORM\Column(nullable: true)]
    private ?int $apiCalls = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private \DateTimeImmutable $queuedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column]
    private int $attempts = 0;

    public function __construct(string $type, ?string $input = null, ?\DateTimeImmutable $queuedAt = null)
    {
        $this->type = $type;
        $this->input = $input;
        $this->queuedAt = $queuedAt ?? new \DateTimeImmutable();
    }

    public function markRunning(): static
    {
        $this->status = 'running';
        $this->startedAt ??= new \DateTimeImmutable();
        $this->attempts++;
        return $this;
    }

    public function markCompleted(int $count): static
    {
        $this->status = 'completed';
        $this->count = $count;
        $this->finishedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markFailed(string $error): static
    {
        $this->status = 'failed';
        $this->error = $error;
        $this->finishedAt = new \DateTimeImmutable();
        return $this;
    }

    public function setApiCalls(int $apiCalls): static
    {
        $this->apiCalls = $apiCalls;
        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getInput(): ?string
    {
        return $this->input;
    }

    public function getCount(): ?int
    {
        return $this->count;
    }

    public function getApiCalls(): ?int
    {
        return $this->apiCalls;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getQueuedAt(): \DateTimeImmutable
    {
        return $this->queuedAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }
}
