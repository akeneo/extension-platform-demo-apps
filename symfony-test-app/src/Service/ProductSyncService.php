<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\SyncLog;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class ProductSyncService
{
    public function __construct(
        private readonly AkeneoApiClient $apiClient,
        private readonly EntityManagerInterface $em,
        private readonly ProductRepository $repository,
        private readonly CategoryRepository $categoryRepository,
        private readonly TagAwareCacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly StockService $stockService,
        private readonly PriceService $priceService,
        #[Autowire(param: 'product_images_dir')]
        private readonly string $imageStorageDir,
    ) {}

    /**
     * Sync a batch of products by identifier from Akeneo. Returns the number synced.
     *
     * @param string[] $identifiers
     */
    public function syncProducts(array $identifiers, string $trigger = 'sync'): int
    {
        $this->ensureImageDir();
        return $this->persistBatch($this->apiClient->getProducts($identifiers), $trigger);
    }

    /**
     * Sync a batch of products by UUID from Akeneo. Returns the number synced.
     *
     * @param string[] $uuids
     */
    public function syncProductsByUuid(array $uuids, string $trigger = 'sync'): int
    {
        $this->ensureImageDir();
        return $this->persistBatch($this->apiClient->getProductsByUuid($uuids), $trigger);
    }

    /**
     * Sync all variant products belonging to the given product model codes.
     *
     * Unlike syncProductsByUuid/syncProducts, the model codes ARE the parent
     * codes — no need to fetch products first to discover them. Resolving
     * axes upfront lets the single products fetch include axis attribute
     * values directly, entirely avoiding the fetch-then-re-fetch-for-axes
     * round trip that the other two entry points need.
     *
     * @param string[] $modelCodes
     */
    public function syncProductsByModelCodes(array $modelCodes, string $trigger = 'sync'): int
    {
        $this->ensureImageDir();

        $parentInfo = $this->resolveParentInfoForCodes($modelCodes);
        $axes = array_values(array_unique(array_merge([], ...array_column($parentInfo, 'axes'))));

        $akeneoProducts = $this->apiClient->getProductsByModelCodes($modelCodes, $axes);

        return $this->persistBatch($akeneoProducts, $trigger, $parentInfo);
    }

    /**
     * Update a single product if it already exists in the local DB.
     * Returns false when the product is not tracked locally.
     */
    public function updateIfExists(string $identifier, bool $isUuid = false): bool
    {
        if ($this->repository->find($identifier) === null) {
            return false;
        }

        $results = $isUuid
            ? $this->apiClient->getProductsByUuid([$identifier])
            : $this->apiClient->getProducts([$identifier]);

        if (empty($results)) {
            return false;
        }

        $this->ensureImageDir();
        $this->persistBatch($results, 'webhook');

        return true;
    }

    /**
     * Shared persistence pipeline for all sync entry points.
     * Fetches parent info (labels + axes), enriches variants with axis values, then persists.
     *
     * $parentInfo can be passed in already resolved (see syncProductsByModelCodes) when the
     * caller fetched products with axis attributes already included — this skips both
     * re-deriving it from the product data and the redundant axis-enrichment re-fetch.
     */
    private function persistBatch(array $akeneoProducts, string $trigger, ?array $parentInfo = null): int
    {
        $this->preSyncCategories($akeneoProducts);

        if ($parentInfo === null) {
            $parentInfo = $this->preSyncParentInfo($akeneoProducts);
            $akeneoProducts = $this->enrichVariantAxisValues($akeneoProducts, $parentInfo);
        }

        // Fetched concurrently for the whole batch rather than one blocking
        // request per product — see PriceService/StockService::get*() docblocks.
        $prices = $this->priceService->getPrices(count($akeneoProducts));
        $stocks = $this->stockService->getStocks(count($akeneoProducts));

        $count = 0;
        foreach (array_values($akeneoProducts) as $i => $data) {
            $this->persistProduct($data, $parentInfo, $prices[$i] ?? null, $stocks[$i] ?? null);
            $count++;
        }

        if ($count > 0) {
            $this->em->persist(new SyncLog($count, $trigger));
        }
        $this->em->flush();

        // Invalidate after flush so the catalogue cache is never populated with pre-commit data
        if ($count > 0) {
            $this->cache->invalidateTags(['catalogue']);
        }

        return $count;
    }

    /**
     * For variants that have parent info with axes, re-fetches just the axis attribute values
     * and merges them into the product data so persistProduct can build a variation label.
     */
    private function enrichVariantAxisValues(array $akeneoProducts, array $parentInfo): array
    {
        $axes = array_unique(array_merge(...array_column($parentInfo, 'axes')));
        if (empty($axes)) {
            return $akeneoProducts;
        }

        $variantUuids = array_filter(array_column(
            array_filter($akeneoProducts, fn($d) => !empty($d['parent'])),
            'uuid',
        ));

        if (empty($variantUuids)) {
            return $akeneoProducts;
        }

        try {
            $enriched = $this->apiClient->getProductsByUuid(array_values($variantUuids), $axes);
            $enrichedMap = array_column($enriched, null, 'uuid');

            return array_map(function (array $data) use ($enrichedMap, $axes): array {
                $uuid = $data['uuid'] ?? null;
                if ($uuid !== null && isset($enrichedMap[$uuid])) {
                    foreach ($axes as $axis) {
                        if (isset($enrichedMap[$uuid]['values'][$axis])) {
                            $data['values'][$axis] = $enrichedMap[$uuid]['values'][$axis];
                        }
                    }
                }
                return $data;
            }, $akeneoProducts);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not enrich variant axis values', ['error' => $e->getMessage()]);
            return $akeneoProducts;
        }
    }

    private function persistProduct(array $data, array $parentInfo, ?float $price, ?int $stock): void
    {
        $uuid     = $data['uuid'] ?? null;
        $legacyId = $data['identifier'] ?? null;

        // Search by UUID first (most products are stored this way), then by legacy identifier.
        // This prevents creating a duplicate entity when Akeneo returns a non-null identifier
        // field alongside the UUID but the local DB still uses the UUID as primary key.
        $product = ($uuid !== null) ? $this->repository->find($uuid) : null;
        $product ??= ($legacyId !== null) ? $this->repository->find($legacyId) : null;

        $identifier = $product?->getIdentifier() ?? $uuid ?? $legacyId;
        $product  ??= new Product($identifier);

        $parentCode = $data['parent'] ?? null;
        $pi         = $parentCode !== null ? ($parentInfo[$parentCode] ?? null) : null;
        $parentLabel = $pi !== null ? ($pi['label'] ?? null) : null;
        $axes        = $pi !== null ? ($pi['axes'] ?? []) : [];

        $descAttr    = $this->apiClient->getDescriptionAttribute();
        $description = null;
        foreach ($data['values'][$descAttr] ?? [] as $value) {
            if ($value['locale'] === 'en_US' || $value['locale'] === null) {
                $description = $value['data'];
                break;
            }
        }
        $product->setDescription($description);

        $labelAttr = $this->apiClient->getLabelAttribute();
        $labelSet  = false;
        $label     = ['en_US' => $legacyId ?? $identifier];
        foreach ($data['values'][$labelAttr] ?? [] as $value) {
            if ($value['locale'] === 'en_US' || $value['locale'] === null) {
                $label['en_US'] = $value['data'];
                $labelSet = true;
                break;
            }
        }
        // When label attribute is defined at model level (not variant level), use parent label
        if (!$labelSet && $parentLabel !== null) {
            $label['en_US'] = $parentLabel;
        }
        $product->setLabel($label);

        $product->setCategories($data['categories'] ?? []);
        $product->setEnabled($data['enabled'] ?? false);
        $product->setParent($parentCode);
        $product->setParentLabel($parentLabel);

        // Build variation label from axis attribute values (e.g. "blue / XL")
        $variationParts = [];
        foreach ($axes as $axis) {
            foreach ($data['values'][$axis] ?? [] as $value) {
                if ($value['data'] !== null) {
                    $variationParts[] = is_array($value['data'])
                        ? implode(', ', $value['data'])
                        : (string) $value['data'];
                    break;
                }
            }
        }
        // Fallback: if axes couldn't be resolved but a legacy identifier exists,
        // strip the parent code prefix to derive a variation hint (e.g. "7M13349_30_30" → "30_30")
        if (empty($variationParts) && $legacyId !== null && $parentCode !== null) {
            $prefix = $parentCode . '_';
            if (str_starts_with($legacyId, $prefix)) {
                $suffix = substr($legacyId, strlen($prefix));
                if ($suffix !== '') {
                    $variationParts[] = $suffix;
                }
            } elseif ($legacyId !== $parentCode) {
                $variationParts[] = $legacyId;
            }
        }
        $product->setVariationLabel($variationParts ? implode(' / ', $variationParts) : null);

        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $identifier);
        $filenames = [];
        foreach ($this->apiClient->getImageAttributes() as $attrIndex => $imageAttr) {
            $imageValues = $data['values'][$imageAttr] ?? [];

            if (empty($imageValues)) {
                $this->logger->info('No image values returned by Akeneo', [
                    'identifier' => $identifier,
                    'attribute' => $imageAttr,
                    'available_attributes' => array_keys($data['values'] ?? []),
                ]);
                continue;
            }

            foreach ($imageValues as $value) {
                $mediaCode = $value['data'] ?? null;
                if ($mediaCode === null) {
                    continue;
                }

                $ext = pathinfo($mediaCode, PATHINFO_EXTENSION) ?: 'jpg';
                $filename = $safeId . '_' . $attrIndex . '.' . $ext;
                $targetPath = $this->imageStorageDir . '/' . $filename;

                try {
                    $this->apiClient->downloadMediaFile($mediaCode, $targetPath);
                    $filenames[] = $filename;
                    $this->logger->info('Image downloaded', ['identifier' => $identifier, 'file' => $filename]);
                } catch (\Throwable $e) {
                    $this->logger->warning('Image download failed', [
                        'identifier' => $identifier,
                        'media_code' => $mediaCode,
                        'error' => $e->getMessage(),
                    ]);
                }

                break; // one image per attribute
            }
        }
        $product->setImageFilenames($filenames ?: null);

        $product->setStock($stock);
        $product->setPrice($price);

        $product->setSyncedAt(new \DateTimeImmutable());
        $this->em->persist($product);
    }

    /**
     * Collects all unique parent model codes from a batch and fetches their labels and variation axes.
     * Returns [code => ['label' => string, 'axes' => string[]]].
     */
    private function preSyncParentInfo(array $akeneoProducts): array
    {
        $codes = array_values(array_unique(array_filter(
            array_column($akeneoProducts, 'parent')
        )));

        return $this->resolveParentInfoForCodes($codes);
    }

    /**
     * Same as preSyncParentInfo, but for when the parent/model codes are already
     * known upfront (syncProductsByModelCodes) instead of derived from fetched
     * product data.
     *
     * @param string[] $codes
     * @return array<string, array{label: string, axes: string[]}>
     */
    private function resolveParentInfoForCodes(array $codes): array
    {
        if (empty($codes)) {
            return [];
        }

        $modelInfo = [];
        try {
            $modelInfo = $this->apiClient->getProductModelInfo($codes);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not fetch parent model info', ['error' => $e->getMessage()]);
        }

        // Fetch family variant axes once per unique (family, family_variant) pair
        $pairAxes = [];
        foreach ($modelInfo as $info) {
            if (empty($info['family']) || empty($info['family_variant'])) {
                continue;
            }
            $key = $info['family'] . '|' . $info['family_variant'];
            if (isset($pairAxes[$key])) {
                continue;
            }
            try {
                $pairAxes[$key] = $this->apiClient->getFamilyVariantAxes($info['family'], $info['family_variant']);
            } catch (\Throwable $e) {
                $this->logger->warning('Could not fetch family variant axes', [
                    'family'         => $info['family'],
                    'family_variant' => $info['family_variant'],
                    'error'          => $e->getMessage(),
                ]);
                $pairAxes[$key] = [];
            }
        }

        $result = [];
        foreach ($codes as $code) {
            $info = $modelInfo[$code] ?? null;
            $key  = ($info !== null && !empty($info['family']) && !empty($info['family_variant']))
                ? $info['family'] . '|' . $info['family_variant']
                : null;

            $result[$code] = [
                'label' => $info['label'] ?? $code,
                'axes'  => $key !== null ? ($pairAxes[$key] ?? []) : [],
            ];
        }

        return $result;
    }

    /**
     * Collects all unique category codes from a batch of Akeneo product data,
     * fetches their labels once, and upserts them — avoiding Doctrine identity
     * map collisions when multiple products share the same categories.
     */
    private function preSyncCategories(array $akeneoProducts): void
    {
        $codes = array_unique(array_merge(
            ...array_map(fn(array $d) => $d['categories'] ?? [], $akeneoProducts)
        ));

        if (empty($codes)) {
            return;
        }

        $labels = $this->apiClient->getCategories($codes);

        $existing = [];
        foreach ($this->categoryRepository->findBy(['code' => $codes]) as $category) {
            $existing[$category->getCode()] = $category;
        }

        foreach ($codes as $code) {
            $category = $existing[$code] ?? new Category($code);
            $category->setLabel($labels[$code] ?? null);
            $this->em->persist($category);
        }
    }

    private function ensureImageDir(): void
    {
        if (!is_dir($this->imageStorageDir)) {
            mkdir($this->imageStorageDir, 0755, true);
        }
    }
}
