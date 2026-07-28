<?php

namespace App\Command;

use App\Entity\SyncAttempt;
use App\Message\ProductSyncMessage;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:daily-sync',
    description: 'Queue a sync message for every product in the catalogue, split into batches of 50.',
)]
class DailySyncCommand extends Command
{
    private const BATCH = 50;
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly ProductRepository $repository,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $products = $this->repository->findAll();

        if (empty($products)) {
            $io->info('No products in catalogue — nothing to sync.');
            return Command::SUCCESS;
        }

        $uuids       = [];
        $identifiers = [];

        foreach ($products as $product) {
            if (preg_match(self::UUID_PATTERN, $product->getIdentifier())) {
                $uuids[] = $product->getIdentifier();
            } else {
                $identifiers[] = $product->getIdentifier();
            }
        }

        $dispatched = 0;

        foreach (array_chunk($uuids, self::BATCH) as $batch) {
            $attempt = new SyncAttempt('daily-sync', count($batch) . ' UUIDs');
            $this->em->persist($attempt);
            $this->em->flush();

            $this->bus->dispatch(new ProductSyncMessage(uuids: $batch, logId: $attempt->getId()));
            $dispatched++;
        }

        foreach (array_chunk($identifiers, self::BATCH) as $batch) {
            $attempt = new SyncAttempt('daily-sync', count($batch) . ' identifiers');
            $this->em->persist($attempt);
            $this->em->flush();

            $this->bus->dispatch(new ProductSyncMessage(identifiers: $batch, logId: $attempt->getId()));
            $dispatched++;
        }

        $io->success(sprintf(
            'Queued %d batch message(s) for %d product(s) (%d UUID-based, %d identifier-based).',
            $dispatched,
            count($products),
            count($uuids),
            count($identifiers),
        ));

        return Command::SUCCESS;
    }
}
