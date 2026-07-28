<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PriceService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ApiCallCounter $apiCallCounter,
        #[Autowire(env: 'PRICE_API_URL')]
        private readonly string $priceApiUrl,
    ) {}

    /**
     * Fetches $count prices concurrently instead of one blocking round-trip at a
     * time — Symfony's HttpClient dispatches requests without waiting, so firing
     * them all up front and resolving afterwards overlaps the network latency
     * instead of stacking it.
     *
     * @return array<int, float|null> Same length as $count, in order.
     */
    public function getPrices(int $count): array
    {
        if ($count === 0) {
            return [];
        }

        $responses = [];
        for ($i = 0; $i < $count; $i++) {
            $this->apiCallCounter->increment();
            $responses[] = $this->httpClient->request('GET', $this->priceApiUrl, ['timeout' => 3]);
        }

        return array_map(function ($response): ?float {
            try {
                $data = $response->toArray();
                return isset($data['price']) ? (float) $data['price'] : null;
            } catch (\Throwable) {
                return null;
            }
        }, $responses);
    }
}
