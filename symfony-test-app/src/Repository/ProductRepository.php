<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * @return array{products: Product[], total: int}
     */
    public function findFiltered(?string $category, ?bool $enabled, int $page, int $perPage): array
    {
        $all = $this->findAllFiltered($category, $enabled);
        $total = count($all);
        $products = array_slice($all, ($page - 1) * $perPage, $perPage);

        return ['products' => $products, 'total' => $total];
    }

    /** @return Product[] */
    public function findAllFiltered(?string $category, ?bool $enabled): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.syncedAt', 'DESC');

        if ($enabled !== null) {
            $qb->andWhere('p.enabled = :enabled')
               ->setParameter('enabled', $enabled);
        }

        $all = $qb->getQuery()->getResult();

        if ($category !== null) {
            $all = array_values(array_filter(
                $all,
                fn(Product $p) => in_array($category, $p->getCategories() ?? [], true)
            ));
        }

        return $all;
    }

    /** @return Product[] */
    public function findVariantsByParent(string $parentCode): array
    {
        return $this->findBy(['parent' => $parentCode], ['syncedAt' => 'DESC']);
    }

    public function findAllCategories(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        try {
            return $conn->executeQuery(
                "SELECT DISTINCT jsonb_array_elements_text(categories::jsonb) AS cat
                 FROM product
                 WHERE categories IS NOT NULL AND categories::text != 'null'
                 ORDER BY cat"
            )->fetchFirstColumn();
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * @return array{
     *   total: int,
     *   enabled: int,
     *   disabled: int,
     *   variants: int,
     *   simple: int,
     *   by_category: list<array{label: string, count: int}>,
     *   synced_by_day: list<array{day: string, count: int}>,
     * }
     */
    public function getStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        try {
            // Totals
            $totals = $conn->executeQuery(
                "SELECT
                    COUNT(*)                                            AS total,
                    COUNT(*) FILTER (WHERE enabled = true)             AS enabled,
                    COUNT(*) FILTER (WHERE enabled = false)            AS disabled,
                    COUNT(*) FILTER (WHERE parent IS NOT NULL)         AS variants,
                    COUNT(*) FILTER (WHERE parent IS NULL)             AS simple
                 FROM product"
            )->fetchAssociative();

            // By category (join with category table for labels)
            $byCategory = $conn->executeQuery(
                "SELECT
                    COALESCE(c.label, cat_code) AS label,
                    COUNT(*)                    AS count
                 FROM product p,
                      jsonb_array_elements_text(
                          CASE
                              WHEN p.categories IS NOT NULL AND p.categories::text != 'null'
                              THEN p.categories::jsonb
                              ELSE '[]'::jsonb
                          END
                      ) AS cat_code
                 LEFT JOIN category c ON c.code = cat_code
                 GROUP BY cat_code, c.label
                 ORDER BY count DESC
                 LIMIT 12"
            )->fetchAllAssociative();

            // Synced by day (last 14 days) — sum log entries so re-syncing a product counts each time
            $syncedByDay = $conn->executeQuery(
                "SELECT
                    TO_CHAR(DATE(synced_at), 'YYYY-MM-DD') AS day,
                    SUM(count)                             AS count
                 FROM sync_log
                 WHERE synced_at >= NOW() - INTERVAL '14 days'
                 GROUP BY DATE(synced_at)
                 ORDER BY DATE(synced_at) ASC"
            )->fetchAllAssociative();

        } catch (\Exception) {
            $totals = ['total' => 0, 'enabled' => 0, 'disabled' => 0, 'variants' => 0, 'simple' => 0];
            $byCategory = [];
            $syncedByDay = [];
        }

        return [
            'total'        => (int) ($totals['total'] ?? 0),
            'enabled'      => (int) ($totals['enabled'] ?? 0),
            'disabled'     => (int) ($totals['disabled'] ?? 0),
            'variants'     => (int) ($totals['variants'] ?? 0),
            'simple'       => (int) ($totals['simple'] ?? 0),
            'by_category'  => array_map(
                fn(array $row) => ['label' => (string) $row['label'], 'count' => (int) $row['count']],
                $byCategory,
            ),
            'synced_by_day' => array_map(
                fn(array $row) => ['day' => (string) $row['day'], 'count' => (int) $row['count']],
                $syncedByDay,
            ),
        ];
    }
}
