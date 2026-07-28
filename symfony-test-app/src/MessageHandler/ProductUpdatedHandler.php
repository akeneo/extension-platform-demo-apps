<?php

namespace App\MessageHandler;

use App\Entity\SyncAttempt;
use App\Message\ProductUpdatedMessage;
use App\Service\ApiCallCounter;
use App\Service\ProductSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ProductUpdatedHandler
{
    public function __construct(
        private readonly ProductSyncService $syncService,
        private readonly EntityManagerInterface $em,
        private readonly ApiCallCounter $apiCallCounter,
    ) {}

    public function __invoke(ProductUpdatedMessage $message): void
    {
        $queuedAt = $message->receivedAt !== null ? new \DateTimeImmutable($message->receivedAt) : null;
        $attempt = new SyncAttempt('webhook', $message->identifier, $queuedAt);
        $this->em->persist($attempt);
        $attempt->markRunning();
        $this->em->flush();

        $this->apiCallCounter->reset();

        try {
            $updated = $this->syncService->updateIfExists($message->identifier, $message->isUuid);
            $attempt->markCompleted($updated ? 1 : 0);
            $attempt->setApiCalls($this->apiCallCounter->getCount());
            $this->em->flush();
        } catch (\Throwable $e) {
            $attempt->markFailed($e->getMessage());
            $attempt->setApiCalls($this->apiCallCounter->getCount());
            $this->em->flush();
            throw $e;
        }
    }
}
