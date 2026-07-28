<?php

namespace App\Command;

use App\Repository\ProductRepository;
use App\Service\AkeneoApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backfill-parent',
    description: 'Backfill the parent field for all products already in the catalogue.',
)]
class BackfillParentCommand extends Command
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const BATCH = 50;

    public function __construct(
        private readonly ProductRepository $repository,
        private readonly AkeneoApiClient $apiClient,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $products = $this->repository->findAll();

        if (empty($products)) {
            $io->info('No products found.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Backfilling parent for %d products…', count($products)));

        // Split by whether the stored identifier is a UUID
        $byUuid = [];
        $byIdentifier = [];
        foreach ($products as $product) {
            if (preg_match(self::UUID_PATTERN, $product->getIdentifier())) {
                $byUuid[] = $product;
            } else {
                $byIdentifier[] = $product;
            }
        }

        $updated = 0;

        // Process UUID-identified products
        foreach (array_chunk($byUuid, self::BATCH) as $batch) {
            $ids = array_map(fn($p) => $p->getIdentifier(), $batch);
            $apiData = $this->apiClient->getProductsByUuid($ids);
            $dataByUuid = [];
            foreach ($apiData as $d) {
                $dataByUuid[$d['uuid']] = $d;
            }
            foreach ($batch as $product) {
                $data = $dataByUuid[$product->getIdentifier()] ?? null;
                if ($data === null) {
                    continue;
                }
                $product->setParent($data['parent'] ?? null);
                $updated++;
            }
            $this->em->flush();
            $io->write('.');
        }

        // Process identifier-based products
        foreach (array_chunk($byIdentifier, self::BATCH) as $batch) {
            $ids = array_map(fn($p) => $p->getIdentifier(), $batch);
            $apiData = $this->apiClient->getProducts($ids);
            $dataByIdentifier = [];
            foreach ($apiData as $d) {
                $dataByIdentifier[$d['identifier']] = $d;
            }
            foreach ($batch as $product) {
                $data = $dataByIdentifier[$product->getIdentifier()] ?? null;
                if ($data === null) {
                    continue;
                }
                $product->setParent($data['parent'] ?? null);
                $updated++;
            }
            $this->em->flush();
            $io->write('.');
        }

        $io->newLine();
        $io->success(sprintf('Done. Updated %d / %d products.', $updated, count($products)));

        return Command::SUCCESS;
    }
}
