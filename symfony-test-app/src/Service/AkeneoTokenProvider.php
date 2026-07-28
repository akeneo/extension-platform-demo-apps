<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AkeneoTokenProvider
{
    private const CACHE_KEY = 'akeneo_api_token';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly ApiCallCounter $apiCallCounter,
        #[Autowire(env: 'AKENEO_BASE_URL')]
        private readonly string $baseUrl,
        #[Autowire(env: 'AKENEO_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'AKENEO_CLIENT_SECRET')]
        private readonly string $clientSecret,
        #[Autowire(env: 'AKENEO_USERNAME')]
        private readonly string $username,
        #[Autowire(env: 'AKENEO_PASSWORD')]
        private readonly string $password,
    ) {}

    public function getToken(): string
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): string {
            $item->expiresAfter(3500);

            $this->apiCallCounter->increment();
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . '/api/oauth/v1/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'grant_type' => 'password',
                    'username' => $this->username,
                    'password' => $this->password,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new \RuntimeException(sprintf(
                    'Akeneo token request failed with HTTP %d: %s',
                    $statusCode,
                    $response->getContent(false),
                ));
            }

            return $response->toArray()['access_token'];
        });
    }

    public function invalidateToken(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }
}
