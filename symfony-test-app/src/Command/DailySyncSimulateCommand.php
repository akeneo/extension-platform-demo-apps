<?php

namespace App\Command;

use App\Entity\SyncAttempt;
use App\Message\ProductSyncMessage;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:daily-sync-simulate',
    description: 'Load-test only: replays the real catalogue (cycled to reach --count) through the same batched sync path as app:daily-sync, to simulate a larger catalogue without touching the product table.',
)]
class DailySyncSimulateCommand extends Command
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

    protected function configure(): void
    {
        $this->addOption('count', null, InputOption::VALUE_REQUIRED, 'Target simulated catalogue size', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $targetCount = (int) $input->getOption('count');

        $products = $this->repository->findAll();

        if (empty($products)) {
            $io->error('No products in catalogue to replay — sync at least one real product first.');
            return Command::FAILURE;
        }

        // Tag each real identifier with its kind, then cycle the combined
        // list up to the target count so uuids+identifiers sum to --count
        // rather than each independently reaching it.
        $tagged = [];
        foreach ($products as $product) {
            $isUuid = (bool) preg_match(self::UUID_PATTERN, $product->getIdentifier());
            $tagged[] = ['id' => $product->getIdentifier(), 'uuid' => $isUuid];
        }

        $realCount = count($tagged);
        $cycled = [];
        for ($i = 0; $i < $targetCount; $i++) {
            $cycled[] = $tagged[$i % $realCount];
        }

        $uuids       = array_values(array_map(fn($t) => $t['id'], array_filter($cycled, fn($t) => $t['uuid'])));
        $identifiers = array_values(array_map(fn($t) => $t['id'], array_filter($cycled, fn($t) => !$t['uuid'])));

        $io->note(sprintf(
            'Replaying %d real product(s), cycled to simulate %d (%d UUID-based, %d identifier-based).',
            $realCount,
            $targetCount,
            count($uuids),
            count($identifiers),
        ));

        $dispatched = 0;

        foreach (array_chunk($uuids, self::BATCH) as $batch) {
            $attempt = new SyncAttempt('daily-sync-simulate', count($batch) . ' UUIDs (simulated)');
            $this->em->persist($attempt);
            $this->em->flush();

            $this->bus->dispatch(new ProductSyncMessage(uuids: $batch, logId: $attempt->getId()));
            $dispatched++;
        }

        foreach (array_chunk($identifiers, self::BATCH) as $batch) {
            $attempt = new SyncAttempt('daily-sync-simulate', count($batch) . ' identifiers (simulated)');
            $this->em->persist($attempt);
            $this->em->flush();

            $this->bus->dispatch(new ProductSyncMessage(identifiers: $batch, logId: $attempt->getId()));
            $dispatched++;
        }

        $io->success(sprintf(
            'Queued %d batch message(s) simulating %d product(s) (%d UUID-based, %d identifier-based).',
            $dispatched,
            count($uuids) + count($identifiers),
            count($uuids),
            count($identifiers),
        ));

        return Command::SUCCESS;
    }
}
