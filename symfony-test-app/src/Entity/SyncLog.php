<?php

namespace App\Entity;

use App\Repository\SyncLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SyncLogRepository::class)]
#[ORM\Table(name: 'sync_log')]
class SyncLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $syncedAt;

    #[ORM\Column]
    private int $count;

    #[ORM\Column(length: 32)]
    private string $trigger;

    public function __construct(int $count, string $trigger = 'sync')
    {
        $this->syncedAt = new \DateTimeImmutable();
        $this->count    = $count;
        $this->trigger  = $trigger;
    }

    public function getId(): ?int { return $this->id; }
    public function getSyncedAt(): \DateTimeImmutable { return $this->syncedAt; }
    public function getCount(): int { return $this->count; }
    public function getTrigger(): string { return $this->trigger; }
}
