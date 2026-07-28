<?php

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-logs',
    description: 'Delete sync attempt logs older than the retention window (14 days).',
)]
class CleanupSyncLogsCommand extends Command
{
    private const RETENTION_DAYS = 14;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', self::RETENTION_DAYS));

        $deleted = $this->em->createQuery('DELETE FROM App\Entity\SyncAttempt a WHERE a.queuedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->execute();

        $io->success(sprintf('Deleted %d sync attempt log(s) older than %d days.', $deleted, self::RETENTION_DAYS));

        return Command::SUCCESS;
    }
}
