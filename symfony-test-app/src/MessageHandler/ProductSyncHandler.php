<?php

namespace App\MessageHandler;

use App\Message\ProductSyncMessage;
use App\Repository\SyncAttemptRepository;
use App\Service\ApiCallCounter;
use App\Service\ProductSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ProductSyncHandler
{
    public function __construct(
        private readonly ProductSyncService $syncService,
        private readonly SyncAttemptRepository $syncAttempts,
        private readonly EntityManagerInterface $em,
        private readonly ApiCallCounter $apiCallCounter,
    ) {}

    public function __invoke(ProductSyncMessage $message): void
    {
        $attempt = $message->logId !== null ? $this->syncAttempts->find($message->logId) : null;
        $attempt?->markRunning();
        $this->em->flush();

        $this->apiCallCounter->reset();

        try {
            $count = 0;
            if (!empty($message->uuids)) {
                $count += $this->syncService->syncProductsByUuid($message->uuids);
            }
            if (!empty($message->identifiers)) {
                $count += $this->syncService->syncProducts($message->identifiers);
            }
            if (!empty($message->modelCodes)) {
                $count += $this->syncService->syncProductsByModelCodes($message->modelCodes);
            }

            $attempt?->markCompleted($count);
            $attempt?->setApiCalls($this->apiCallCounter->getCount());
            $this->em->flush();
        } catch (\Throwable $e) {
            $attempt?->markFailed($e->getMessage());
            $attempt?->setApiCalls($this->apiCallCounter->getCount());
            $this->em->flush();
            throw $e;
        }
    }
}
