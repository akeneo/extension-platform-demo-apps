<?php

namespace App\Controller;

use App\Message\ProductUpdatedMessage;
use App\Security\HmacSignatureValidator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class WebhookController extends AbstractController
{
    public function __construct(
        private readonly HmacSignatureValidator $validator,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'AKENEO_WEBHOOK_SECRET')]
        private readonly string $webhookSecret,
    ) {}

    /**
     * Receives product update events from the Akeneo Event Platform.
     * Signed with HmacSHA256 in x-akeneo-signature-primary (primary key)
     * and x-akeneo-signature-secondary (secondary key, used during rotation).
     * Payload: {"data": {"product": {"uuid": "..."}}, "id": "...", ...}
     */
    #[Route('/webhook/product-updated', name: 'webhook_product_updated', methods: ['POST'])]
    public function handle(Request $request): JsonResponse
    {
        $secrets = array_filter(array_map('trim', explode(',', $this->webhookSecret)));

        $primary = $request->headers->get('x-akeneo-signature-primary', '');
        $secondary = $request->headers->get('x-akeneo-signature-secondary', '');

        $valid = ($primary !== '' && $this->validateAgainstSecrets($request, $primary, $secrets))
            || ($secondary !== '' && $this->validateAgainstSecrets($request, $secondary, $secrets));

        if (!$valid) {
            $this->logger->warning('Webhook signature invalid');
            return $this->json(['error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode($request->getContent(), true);

        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        // Wrap single event in array; handle batched arrays too
        $events = isset($body[0]) ? $body : [$body];

        $receivedAt = (new \DateTimeImmutable())->format(\DATE_ATOM);

        $queued = 0;
        foreach ($events as $event) {
            $uuid = $event['data']['product']['uuid'] ?? null;

            if ($uuid === null) {
                $this->logger->warning('Event missing product uuid', ['event_id' => $event['id'] ?? null]);
                continue;
            }

            // The sync-attempt log row is created by ProductUpdatedHandler instead of here,
            // so the ack path never blocks on a Postgres write — logging is observation-only.
            $this->bus->dispatch(new ProductUpdatedMessage($uuid, isUuid: true, receivedAt: $receivedAt));
            $queued++;
        }

        $this->logger->info('Webhook events queued', ['queued' => $queued]);

        return $this->json(['queued' => $queued]);
    }

    private function validateAgainstSecrets(Request $request, string $signature, array $secrets): bool
    {
        foreach ($secrets as $secret) {
            if (hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
                return true;
            }
        }
        return false;
    }
}
