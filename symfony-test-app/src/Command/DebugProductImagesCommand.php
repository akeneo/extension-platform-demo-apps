<?php

namespace App\Command;

use App\Repository\ProductRepository;
use App\Service\AkeneoApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:debug-images',
    description: 'Dump raw Akeneo image values and DB state for a product to debug multi-image sync.',
)]
class DebugProductImagesCommand extends Command
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly AkeneoApiClient $apiClient,
        private readonly ProductRepository $repository,
        #[Autowire(param: 'product_images_dir')]
        private readonly string $imageStorageDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('identifier', InputArgument::OPTIONAL, 'Product identifier or UUID (defaults to first in DB)');
        $this->addOption('download', 'd', InputOption::VALUE_NONE, 'Actually attempt to download images and report result');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $identifier = $input->getArgument('identifier');
        if ($identifier === null) {
            $product = $this->repository->findOneBy([]);
            if ($product === null) {
                $io->error('No products in database.');
                return Command::FAILURE;
            }
            $identifier = $product->getIdentifier();
            $io->info(sprintf('Using first product in DB: %s', $identifier));
        }

        $isUuid = (bool) preg_match(self::UUID_PATTERN, $identifier);
        $imageAttributes = $this->apiClient->getImageAttributes();

        $io->section('Configured image attributes');
        $io->listing($imageAttributes);

        // ── DB state ─────────────────────────────────────────────────────────
        $dbProduct = $this->repository->find($identifier);
        if ($dbProduct) {
            $stored = $dbProduct->getImageFilenames() ?? [];
            $io->section('DB image_filenames');
            if (empty($stored)) {
                $io->writeln('<comment>empty / null</comment>');
            } else {
                foreach ($stored as $f) {
                    $path = $this->imageStorageDir . '/' . $f;
                    $exists = is_file($path) ? '<info>EXISTS</info>' : '<error>MISSING on disk</error>';
                    $io->writeln(sprintf('  %s — %s', $f, $exists));
                }
            }
        } else {
            $io->writeln('<comment>Product not found in local DB (will only test API)</comment>');
        }

        // ── Akeneo API ────────────────────────────────────────────────────────
        $io->section('Fetching from Akeneo API');
        $results = $isUuid
            ? $this->apiClient->getProductsByUuid([$identifier])
            : $this->apiClient->getProducts([$identifier]);

        if (empty($results)) {
            $io->error('Product not found in Akeneo.');
            return Command::FAILURE;
        }

        $data = $results[0];
        $io->text(sprintf('uuid/identifier: %s', $data['uuid'] ?? $data['identifier']));
        $io->text(sprintf('parent: %s', $data['parent'] ?? '(none)'));
        $io->newLine();

        $mediaCodes = [];
        $io->section('Image attribute values from API');
        foreach ($imageAttributes as $attr) {
            $values = $data['values'][$attr] ?? null;
            if ($values === null) {
                $io->writeln(sprintf('<error>%s</error>: NOT in API response (attribute missing from values)', $attr));
                continue;
            }
            if (empty($values)) {
                $io->writeln(sprintf('<comment>%s</comment>: empty array — no value set in PIM', $attr));
                continue;
            }
            foreach ($values as $v) {
                $mediaCode = $v['data'] ?? null;
                $io->writeln(sprintf(
                    '<info>%s</info>: scope=%s locale=%s data=%s',
                    $attr,
                    $v['scope'] ?? 'null',
                    $v['locale'] ?? 'null',
                    $mediaCode ?? '(null)',
                ));
                if ($mediaCode) {
                    $mediaCodes[$attr] = $mediaCode;
                }
            }
        }

        // ── Optional download test ────────────────────────────────────────────
        if ($input->getOption('download')) {
            $io->section('Download test (--download flag)');
            $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $identifier);
            foreach ($mediaCodes as $attr => $mediaCode) {
                $attrIndex = array_search($attr, array_values($imageAttributes));
                $ext = pathinfo($mediaCode, PATHINFO_EXTENSION) ?: 'jpg';
                $filename = $safeId . '_' . $attrIndex . '.' . $ext;
                $targetPath = $this->imageStorageDir . '/debug_' . $filename;
                try {
                    $this->apiClient->downloadMediaFile($mediaCode, $targetPath);
                    $size = filesize($targetPath);
                    $io->writeln(sprintf('<info>%s</info>: downloaded → debug_%s (%d bytes)', $attr, $filename, $size));
                    unlink($targetPath);
                } catch (\Throwable $e) {
                    $io->writeln(sprintf('<error>%s</error>: FAILED — %s', $attr, $e->getMessage()));
                }
            }
        } else {
            $io->writeln('<comment>Tip: add --download to actually test image downloads</comment>');
        }

        return Command::SUCCESS;
    }
}
