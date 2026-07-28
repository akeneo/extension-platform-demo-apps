<?php

namespace App\Repository;

use App\Entity\SyncAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SyncAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncAttempt::class);
    }

    /** @return SyncAttempt[] */
    public function findPage(int $limit = 100, int $offset = 0, string $order = 'DESC', ?string $type = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.queuedAt', $order === 'ASC' ? 'ASC' : 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if ($type !== null) {
            $qb->andWhere('a.type = :type')->setParameter('type', $type);
        }

        return $qb->getQuery()->getResult();
    }

    public function countAll(?string $type = null): int
    {
        $qb = $this->createQueryBuilder('a')->select('COUNT(a.id)');

        if ($type !== null) {
            $qb->andWhere('a.type = :type')->setParameter('type', $type);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Counts queued/running attempts from the last hour, for the sync-in-progress
     * indicator. The time bound avoids a crashed worker permanently showing "syncing".
     *
     * @return array{pending: int, processing: int}
     */
    public function countActive(): array
    {
        $since = new \DateTimeImmutable('-1 hour');

        $countByStatus = fn(string $status): int => (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.status = :status')
            ->andWhere('a.queuedAt > :since')
            ->setParameter('status', $status)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'pending'    => $countByStatus('queued'),
            'processing' => $countByStatus('running'),
        ];
    }
}
