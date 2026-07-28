<?php

namespace App\Command;

use App\Service\ProductSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-now',
    description: 'Synchronously sync one or more products by UUID or identifier (bypasses the queue).',
)]
class SyncNowCommand extends Command
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly ProductSyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('identifiers', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'UUIDs or identifiers to sync');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $args = $input->getArgument('identifiers');

        $uuids = array_values(array_filter($args, fn(string $id) => preg_match(self::UUID_PATTERN, $id)));
        $identifiers = array_values(array_filter($args, fn(string $id) => !preg_match(self::UUID_PATTERN, $id)));

        $io->info(sprintf('Syncing %d UUID(s) and %d identifier(s) directly...', count($uuids), count($identifiers)));

        try {
            if (!empty($uuids)) {
                $io->writeln('Calling syncProductsByUuid...');
                $count = $this->syncService->syncProductsByUuid($uuids);
                $io->success(sprintf('Synced %d product(s) by UUID.', $count));
            }
            if (!empty($identifiers)) {
                $io->writeln('Calling syncProducts...');
                $count = $this->syncService->syncProducts($identifiers);
                $io->success(sprintf('Synced %d product(s) by identifier.', $count));
            }
        } catch (\Throwable $e) {
            $io->error(sprintf('%s: %s', get_class($e), $e->getMessage()));
            $io->writeln($e->getTraceAsString());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
