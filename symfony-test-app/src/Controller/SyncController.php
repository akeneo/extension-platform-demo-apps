<?php

namespace App\Controller;

use App\Entity\SyncAttempt;
use App\Message\ProductSyncMessage;
use App\Repository\SyncAttemptRepository;
use App\Security\HmacSignatureValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class SyncController extends AbstractController
{
    public function __construct(
        private readonly HmacSignatureValidator $validator,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        #[Autowire(env: 'AKENEO_SYNC_SECRET')]
        private readonly string $syncSecret,
        #[Autowire(env: 'AKENEO_BASE_URL')]
        private readonly string $akeneoBaseUrl,
    ) {}

    #[Route('/api/sync/status', name: 'api_sync_status', methods: ['GET'])]
    public function status(SyncAttemptRepository $syncAttempts): JsonResponse
    {
        $counts = $syncAttempts->countActive();

        return $this->json([
            'in_progress' => ($counts['pending'] + $counts['processing']) > 0,
            'pending'     => $counts['pending'],
            'processing'  => $counts['processing'],
        ]);
    }

    #[Route('/api/products/sync', name: 'api_sync_preflight', methods: ['OPTIONS'])]
    public function preflight(Request $request): Response
    {
        return $this->corsResponse(new Response('', Response::HTTP_NO_CONTENT), $request);
    }

    #[Route('/api/products/sync', name: 'api_sync', methods: ['POST'])]
    public function sync(Request $request): JsonResponse
    {
        $secrets = array_filter(array_map('trim', explode(',', $this->syncSecret)));

        if (!$this->validator->validate($request, $secrets)) {
            $rejected = new SyncAttempt('rejected', mb_substr($request->getContent(), 0, 500));
            $rejected->markFailed('Invalid signature');
            $this->em->persist($rejected);
            $this->em->flush();

            return $this->corsResponse(
                $this->json(['error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED),
                $request,
            );
        }

        $body = json_decode($request->getContent(), true);
        $data = $body['data'] ?? [];

        $uuids       = $this->extractValues($data, ['productUuid', 'productUuids']);
        $identifiers = $this->extractValues($data, ['productIdentifier', 'productIdentifiers']);
        $modelCodes  = $this->extractValues($data, ['productModelCode', 'productModelCodes']);

        if (empty($uuids) && empty($identifiers) && empty($modelCodes)) {
            return $this->corsResponse(
                $this->json(['error' => 'No product UUID, identifier, or model code found in payload data'], Response::HTTP_BAD_REQUEST),
                $request,
            );
        }

        // One attempt (and one message) per type, so the Logs page shows a
        // separate row per kind of thing that was synced instead of one
        // combined entry — matches DailySyncCommand and the Node app.
        if (!empty($uuids)) {
            $attempt = new SyncAttempt('sync', $this->describeCount($uuids, 'UUID'));
            $this->em->persist($attempt);
            $this->em->flush();
            $this->bus->dispatch(new ProductSyncMessage(uuids: $uuids, logId: $attempt->getId()));
        }
        if (!empty($identifiers)) {
            $attempt = new SyncAttempt('sync', $this->describeCount($identifiers, 'identifier'));
            $this->em->persist($attempt);
            $this->em->flush();
            $this->bus->dispatch(new ProductSyncMessage(identifiers: $identifiers, logId: $attempt->getId()));
        }
        if (!empty($modelCodes)) {
            $attempt = new SyncAttempt('sync', $this->describeCount($modelCodes, 'model code'));
            $this->em->persist($attempt);
            $this->em->flush();
            $this->bus->dispatch(new ProductSyncMessage(modelCodes: $modelCodes, logId: $attempt->getId()));
        }

        $this->logger->info('Product sync queued', [
            'uuids'       => $uuids,
            'identifiers' => $identifiers,
            'modelCodes'  => $modelCodes,
        ]);

        return $this->corsResponse(
            $this->json(['queued' => count($uuids) + count($identifiers)]),
            $request,
        );
    }

    /**
     * @param string[] $values
     */
    private function describeCount(array $values, string $noun): string
    {
        return count($values) === 1 ? $values[0] : count($values) . ' ' . $noun . 's';
    }

    private function extractValues(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                return is_array($data[$key]) ? $data[$key] : [$data[$key]];
            }
        }

        return [];
    }

    private function corsResponse(Response $response, Request $request): Response
    {
        $origin = $request->headers->get('Origin', '');
        $allowed = rtrim($this->akeneoBaseUrl, '/');

        if ($origin !== '' && (str_starts_with($origin, $allowed) || str_contains($origin, '.akeneo.com'))) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Akeneo-Request-Signature');
            $response->headers->set('Access-Control-Max-Age', '3600');
        }

        return $response;
    }
}
