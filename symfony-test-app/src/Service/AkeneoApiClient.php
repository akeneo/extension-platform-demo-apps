<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AkeneoApiClient
{
    private const LOCALE = 'en_US';
    private const CHANNEL = 'ecommerce';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AkeneoTokenProvider $tokenProvider,
        private readonly ApiCallCounter $apiCallCounter,
        #[Autowire(env: 'AKENEO_BASE_URL')]
        private readonly string $baseUrl,
        #[Autowire(env: 'AKENEO_IMAGE_ATTRIBUTE')]
        private readonly string $imageAttributeEnv,
        #[Autowire(env: 'AKENEO_LABEL_ATTRIBUTE')]
        private readonly string $labelAttribute,
        #[Autowire(env: 'AKENEO_DESCRIPTION_ATTRIBUTE')]
        private readonly string $descriptionAttribute,
    ) {}

    /**
     * Fetch products by identifiers from Akeneo REST API.
     *
     * @param string[] $identifiers
     * @param string[] $extraAttributes Additional attribute codes to include in the response
     * @return array<int, array<string, mixed>>
     */
    public function getProducts(array $identifiers, array $extraAttributes = []): array
    {
        if (empty($identifiers)) {
            return [];
        }

        $items = [];
        $attributes = array_unique(array_filter(array_merge([$this->labelAttribute, $this->descriptionAttribute], $this->getImageAttributes(), $extraAttributes)));

        foreach (array_chunk($identifiers, 50) as $batch) {
            $response = $this->request('GET', '/api/rest/v1/products', [
                'query' => [
                    'search' => json_encode([
                        'identifier' => [['operator' => 'IN', 'value' => $batch]],
                    ]),
                    'locales' => self::LOCALE,
                    'attributes' => implode(',', $attributes),
                    'limit' => count($batch),
                ],
            ]);

            array_push($items, ...($response['_embedded']['items'] ?? []));
        }

        return $items;
    }

    /**
     * Fetch category labels by code. Returns [code => label].
     *
     * @param string[] $codes
     * @return array<string, string>
     */
    public function getCategories(array $codes): array
    {
        if (empty($codes)) {
            return [];
        }

        $labels = [];

        foreach (array_chunk($codes, 100) as $batch) {
            $response = $this->request('GET', '/api/rest/v1/categories', [
                'query' => [
                    'search' => json_encode([
                        'code' => [['operator' => 'IN', 'value' => $batch]],
                    ]),
                    'limit' => count($batch),
                ],
            ]);

            foreach ($response['_embedded']['items'] ?? [] as $item) {
                $labels[$item['code']] = $item['labels'][self::LOCALE] ?? $item['code'];
            }
        }

        return $labels;
    }

    /**
     * Fetch products by UUID from Akeneo REST API.
     *
     * @param string[] $uuids
     * @param string[] $extraAttributes Additional attribute codes to include in the response
     * @return array<int, array<string, mixed>>
     */
    public function getProductsByUuid(array $uuids, array $extraAttributes = []): array
    {
        if (empty($uuids)) {
            return [];
        }

        $items = [];
        $attributes = array_unique(array_filter(array_merge([$this->labelAttribute, $this->descriptionAttribute], $this->getImageAttributes(), $extraAttributes)));

        foreach (array_chunk($uuids, 50) as $batch) {
            $response = $this->request('GET', '/api/rest/v1/products-uuid', [
                'query' => [
                    'search' => json_encode([
                        'uuid' => [['operator' => 'IN', 'value' => $batch]],
                    ]),
                    'locales' => self::LOCALE,
                    'attributes' => implode(',', $attributes),
                    'limit' => count($batch),
                ],
            ]);

            array_push($items, ...($response['_embedded']['items'] ?? []));
        }

        return $items;
    }

    /**
     * Download a media file binary into the target path.
     * The $mediaCode is the file path as returned in product values (e.g. "1/4/7/a/abc.jpg").
     */
    public function downloadMediaFile(string $mediaCode, string $targetPath): void
    {
        $encodedCode = implode('/', array_map('rawurlencode', explode('/', $mediaCode)));

        $this->apiCallCounter->increment();
        $response = $this->httpClient->request(
            'GET',
            rtrim($this->baseUrl, '/') . '/api/rest/v1/media-files/' . $encodedCode . '/download',
            ['headers' => ['Authorization' => 'Bearer ' . $this->tokenProvider->getToken()]],
        );

        file_put_contents($targetPath, $response->getContent());
    }

    /**
     * Fetch all variant products belonging to the given product model codes.
     * Paginates automatically since variant counts are unknown upfront.
     *
     * @param string[] $codes
     * @param string[] $extraAttributes Additional attribute codes to include in the response
     * @return array<int, array<string, mixed>>
     */
    public function getProductsByModelCodes(array $codes, array $extraAttributes = []): array
    {
        if (empty($codes)) {
            return [];
        }

        $items = [];
        $attributes = array_unique(array_filter(array_merge([$this->labelAttribute, $this->descriptionAttribute], $this->getImageAttributes(), $extraAttributes)));

        foreach (array_chunk($codes, 25) as $batch) {
            $page = 1;
            do {
                $response = $this->request('GET', '/api/rest/v1/products-uuid', [
                    'query' => [
                        'search' => json_encode([
                            'parent' => [['operator' => 'IN', 'value' => $batch]],
                        ]),
                        'locales'    => self::LOCALE,
                        'attributes' => implode(',', $attributes),
                        'limit'      => 100,
                        'page'       => $page,
                    ],
                ]);

                $pageItems = $response['_embedded']['items'] ?? [];
                array_push($items, ...$pageItems);
                $page++;
            } while (isset($response['_links']['next']) && count($pageItems) > 0);
        }

        return $items;
    }

    /**
     * Fetch a single product model by code. Returns null if not found.
     */
    private function fetchProductModelByCode(string $code): ?array
    {
        try {
            return $this->request('GET', '/api/rest/v1/product-models/' . rawurlencode($code));
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Fetch the axis attribute codes for a family variant.
     * Returns a flat list of attribute codes used as axes across all levels.
     *
     * @return string[]
     */
    public function getFamilyVariantAxes(string $family, string $variantCode): array
    {
        $response = $this->request(
            'GET',
            '/api/rest/v1/families/' . rawurlencode($family) . '/variants/' . rawurlencode($variantCode),
        );

        $axes = [];
        foreach ($response['variant_attribute_sets'] ?? [] as $set) {
            foreach ($set['axes'] ?? [] as $axis) {
                $axes[] = $axis;
            }
        }

        return array_values(array_unique($axes));
    }

    public function getLabelAttribute(): string
    {
        return $this->labelAttribute;
    }

    public function getDescriptionAttribute(): string
    {
        return $this->descriptionAttribute;
    }

    /** @return string[] */
    public function getImageAttributes(): array
    {
        return array_filter(array_map('trim', explode(',', $this->imageAttributeEnv)));
    }

    /**
     * Fetch label, family, and family_variant for a set of product model codes.
     * A single GET per code carries all three, so this replaces what used to be
     * two separate per-code loops (label lookup + family/variant lookup) each
     * fetching the exact same resource.
     * Uses individual GET endpoints to support all Akeneo versions.
     *
     * @param string[] $codes
     * @return array<string, array{label: string, family: ?string, family_variant: ?string}>
     */
    public function getProductModelInfo(array $codes): array
    {
        $result = [];
        foreach ($codes as $code) {
            $item = $this->fetchProductModelByCode($code);
            if ($item === null) {
                continue;
            }

            $label = null;
            foreach ($item['values'][$this->labelAttribute] ?? [] as $value) {
                if ($value['locale'] === self::LOCALE || $value['locale'] === null) {
                    $label = $value['data'];
                    break;
                }
            }

            $result[$code] = [
                'label'          => $label ?? $code,
                'family'         => $item['family'] ?? null,
                'family_variant' => $item['family_variant'] ?? null,
            ];
        }
        return $result;
    }

    private function request(string $method, string $path, array $options = []): array
    {
        $options['headers']['Authorization'] = 'Bearer ' . $this->tokenProvider->getToken();

        $this->apiCallCounter->increment();
        $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/') . $path, $options);

        // On 401 the token may have expired mid-request; retry once with a fresh token.
        if ($response->getStatusCode() === 401) {
            $this->tokenProvider->invalidateToken();
            $options['headers']['Authorization'] = 'Bearer ' . $this->tokenProvider->getToken();
            $this->apiCallCounter->increment();
            $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/') . $path, $options);
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf(
                'Akeneo API %s %s failed with HTTP %d: %s',
                $method,
                $path,
                $statusCode,
                $response->getContent(false),
            ));
        }

        return $response->toArray();
    }
}
