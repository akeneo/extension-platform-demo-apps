<?php

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Returns Category entities whose code appears in at least one product's categories JSON.
     * Ordered by label then code.
     *
     * @return Category[]
     */
    public function findUsedInProducts(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $codes = $conn->executeQuery(
            "SELECT DISTINCT jsonb_array_elements_text(p.categories::jsonb) AS code
             FROM product p
             WHERE p.categories IS NOT NULL AND p.categories::text != 'null'"
        )->fetchFirstColumn();

        if (empty($codes)) {
            return [];
        }

        $results = $this->createQueryBuilder('c')
            ->where('c.code IN (:codes)')
            ->setParameter('codes', $codes, \Doctrine\DBAL\ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();

        usort($results, fn(Category $a, Category $b) => strcmp(
            $a->getLabel() ?? $a->getCode(),
            $b->getLabel() ?? $b->getCode(),
        ));

        return $results;
    }

    /**
     * Returns [code => label] for all stored categories.
     *
     * @return array<string, string>
     */
    public function getLabelMap(): array
    {
        $map = [];
        foreach ($this->findAll() as $category) {
            $map[$category->getCode()] = $category->getLabel() ?? $category->getCode();
        }
        return $map;
    }
}
