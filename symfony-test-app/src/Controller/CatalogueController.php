<?php

namespace App\Controller;

use App\Entity\Product;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class CatalogueController extends AbstractController
{
    private const PER_PAGE = 12;

    #[Route('/', name: 'catalogue_index', methods: ['GET'])]
    public function index(CategoryRepository $categoryRepository): Response
    {
        return $this->render('catalogue/index.html.twig', [
            'categories' => $categoryRepository->findUsedInProducts(),
        ]);
    }

    #[Route('/product/{identifier}', name: 'catalogue_product', methods: ['GET'])]
    public function product(
        string $identifier,
        ProductRepository $repository,
        CategoryRepository $categoryRepository,
    ): Response {
        $product = $repository->find($identifier);

        if ($product === null || $product->isVariant()) {
            throw $this->createNotFoundException();
        }

        $labelMap = $categoryRepository->getLabelMap();

        return $this->render('catalogue/product.html.twig', [
            'product' => $this->serializeProduct($product, $labelMap),
        ]);
    }

    #[Route('/model/{parentCode}', name: 'catalogue_model', methods: ['GET'], requirements: ['parentCode' => '.+'])]
    public function model(
        string $parentCode,
        ProductRepository $repository,
        CategoryRepository $categoryRepository,
    ): Response {
        $variants = $repository->findVariantsByParent($parentCode);

        if (empty($variants)) {
            throw $this->createNotFoundException();
        }

        $labelMap = $categoryRepository->getLabelMap();
        $modelLabel = $variants[0]->getParentLabel() ?? $parentCode;

        return $this->render('catalogue/model.html.twig', [
            'model_code'  => $parentCode,
            'model_label' => $modelLabel,
            'variants'    => array_map(fn($v) => $this->serializeProduct($v, $labelMap), $variants),
        ]);
    }

    #[Route('/api/products', name: 'api_products', methods: ['GET'])]
    public function list(
        Request $request,
        ProductRepository $repository,
        CategoryRepository $categoryRepository,
        TagAwareCacheInterface $cache,
    ): JsonResponse {
        $page          = max(1, (int) $request->query->get('page', 1));
        $category      = $request->query->get('category') ?: null;
        $enabledParam  = $request->query->get('enabled');
        $enabled       = $enabledParam !== null ? filter_var($enabledParam, FILTER_VALIDATE_BOOLEAN) : null;

        $cacheKey = 'catalogue_' . md5(serialize([$page, $category, $enabled]));

        $data = $cache->get($cacheKey, function (ItemInterface $item) use (
            $repository, $categoryRepository, $page, $category, $enabled
        ): array {
            $item->expiresAfter(300);
            $item->tag(['catalogue']);

            $all      = $repository->findAllFiltered($category, $enabled);
            $labelMap = $categoryRepository->getLabelMap();

            // Group variants by parent; simple products stay individual
            $simple      = [];
            $modelGroups = [];
            foreach ($all as $product) {
                if ($product->isVariant()) {
                    $modelGroups[$product->getParent()][] = $product;
                } else {
                    $simple[] = $product;
                }
            }

            // Build sortable entries
            $entries = [];
            foreach ($simple as $p) {
                $entries[] = [
                    'type'    => 'product',
                    'product' => $p,
                    'ts'      => $p->getSyncedAt()?->getTimestamp() ?? 0,
                ];
            }
            foreach ($modelGroups as $parentCode => $variants) {
                $ts = max(array_map(fn($v) => $v->getSyncedAt()?->getTimestamp() ?? 0, $variants));
                $entries[] = [
                    'type'     => 'model',
                    'code'     => $parentCode,
                    'variants' => $variants,
                    'ts'       => $ts,
                ];
            }

            usort($entries, fn($a, $b) => $b['ts'] - $a['ts']);

            $total       = count($entries);
            $pageEntries = array_slice($entries, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

            $serialized = array_map(
                fn($e) => $e['type'] === 'model'
                    ? $this->serializeModel($e['code'], $e['variants'], $labelMap)
                    : $this->serializeProduct($e['product'], $labelMap),
                $pageEntries,
            );

            return [
                'products' => $serialized,
                'total'    => $total,
                'page'     => $page,
                'per_page' => self::PER_PAGE,
                'has_more' => ($page * self::PER_PAGE) < $total,
            ];
        });

        return $this->json($data);
    }

    private function serializeProduct(Product $product, array $categoryLabels = []): array
    {
        return [
            'type'            => 'product',
            'identifier'      => $product->getIdentifier(),
            'label'           => $product->getLabelForLocale('en_US'),
            'image_urls'      => array_values(array_map(
                fn(string $f) => '/images/' . $f,
                $product->getImageFilenames() ?? [],
            )),
            'categories'      => array_map(
                fn(string $code) => ['code' => $code, 'label' => $categoryLabels[$code] ?? $code],
                $product->getCategories() ?? [],
            ),
            'enabled'         => $product->isEnabled(),
            'is_variant'      => $product->isVariant(),
            'parent'          => $product->getParent(),
            'parent_label'    => $product->getParentLabel(),
            'variation_label' => $product->getVariationLabel(),
            'description'     => $product->getDescription(),
            'completeness'    => $product->getCompleteness(),
            'stock'           => $product->getStock(),
            'price'           => $product->getPrice(),
            'synced_at'       => $product->getSyncedAt()?->format('c'),
        ];
    }

    /** @param Product[] $variants */
    private function serializeModel(string $parentCode, array $variants, array $categoryLabels = []): array
    {
        // Use the first variant that has images as the cover image source
        $cover = null;
        foreach ($variants as $v) {
            if (!empty($v->getImageFilenames())) {
                $cover = $v;
                break;
            }
        }
        $cover ??= $variants[0];

        // Union of all variants' categories
        $categoryCodes = array_values(array_unique(array_merge(
            ...array_map(fn($v) => $v->getCategories() ?? [], $variants)
        )));

        $enabledCount = count(array_filter($variants, fn($v) => $v->isEnabled()));

        return [
            'type'          => 'model',
            'parent'        => $parentCode,
            'parent_label'  => $cover->getParentLabel() ?? $parentCode,
            'image_urls'    => array_values(array_map(
                fn(string $f) => '/images/' . $f,
                $cover->getImageFilenames() ?? [],
            )),
            'categories'    => array_map(
                fn($code) => ['code' => $code, 'label' => $categoryLabels[$code] ?? $code],
                $categoryCodes,
            ),
            'variant_count' => count($variants),
            'enabled_count' => $enabledCount,
        ];
    }
}
