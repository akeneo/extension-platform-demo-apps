<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class ProductController extends AbstractController
{
    #[Route('/api/model/{parentCode}', name: 'api_model_delete', methods: ['DELETE'], requirements: ['parentCode' => '.+'])]
    public function deleteModel(
        string $parentCode,
        ProductRepository $repository,
        EntityManagerInterface $em,
        TagAwareCacheInterface $cache,
        #[Autowire(param: 'product_images_dir')] string $imageDir,
    ): JsonResponse {
        $variants = $repository->findVariantsByParent($parentCode);

        if (empty($variants)) {
            return $this->json(['error' => 'Model not found'], Response::HTTP_NOT_FOUND);
        }

        foreach ($variants as $variant) {
            foreach ($variant->getImageFilenames() ?? [] as $filename) {
                $path = $imageDir . '/' . $filename;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $em->remove($variant);
        }

        $em->flush();
        $cache->invalidateTags(['catalogue']);

        return $this->json(['deleted' => count($variants)]);
    }

    #[Route('/api/products/{identifier}', name: 'api_product_delete', methods: ['DELETE'])]
    public function delete(
        string $identifier,
        ProductRepository $repository,
        EntityManagerInterface $em,
        TagAwareCacheInterface $cache,
        #[Autowire(param: 'product_images_dir')] string $imageDir,
    ): JsonResponse {
        $product = $repository->find($identifier);

        if (!$product) {
            return $this->json(['error' => 'Product not found'], Response::HTTP_NOT_FOUND);
        }

        foreach ($product->getImageFilenames() ?? [] as $filename) {
            $path = $imageDir . '/' . $filename;
            if (is_file($path)) {
                unlink($path);
            }
        }

        $em->remove($product);
        $em->flush();

        $cache->invalidateTags(['catalogue']);

        return $this->json(['deleted' => true]);
    }

    #[Route('/api/products', name: 'api_products_delete_all', methods: ['DELETE'])]
    public function deleteAll(
        ProductRepository $repository,
        Connection $connection,
        TagAwareCacheInterface $cache,
        #[Autowire(param: 'product_images_dir')] string $imageDir,
    ): JsonResponse {
        // Delete image files from disk first
        $filenames = $connection->fetchFirstColumn('SELECT image_filenames FROM product WHERE image_filenames IS NOT NULL');
        foreach ($filenames as $json) {
            foreach (json_decode($json, true) ?? [] as $filename) {
                $path = $imageDir . '/' . $filename;
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        $deleted = $connection->executeStatement('DELETE FROM product');

        $cache->invalidateTags(['catalogue']);

        return $this->json(['deleted' => $deleted]);
    }
}
