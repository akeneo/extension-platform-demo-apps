<?php

namespace App\Controller;

use App\Entity\SyncAttempt;
use App\Repository\SyncAttemptRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LogsController extends AbstractController
{
    #[Route('/logs', name: 'logs_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('logs/index.html.twig');
    }

    #[Route('/api/logs', name: 'api_logs', methods: ['GET'])]
    public function logs(Request $request, SyncAttemptRepository $repository): JsonResponse
    {
        $type   = $request->query->get('type');
        $limit  = min(500, max(1, $request->query->getInt('limit', 100)));
        $offset = max(0, $request->query->getInt('offset', 0));
        $order  = $request->query->get('order') === 'asc' ? 'ASC' : 'DESC';

        return $this->json([
            'entries' => array_map(
                fn(SyncAttempt $a) => $this->serialize($a),
                $repository->findPage($limit, $offset, $order, $type),
            ),
            'total'  => $repository->countAll($type),
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    private function serialize(SyncAttempt $a): array
    {
        $startedAt = $a->getStartedAt();
        $finishedAt = $a->getFinishedAt();
        $duration = ($startedAt !== null && $finishedAt !== null)
            ? ($finishedAt->getTimestamp() - $startedAt->getTimestamp()) * 1000
            : null;

        return [
            'id'         => $a->getId(),
            'type'       => $a->getType(),
            'status'     => $a->getStatus(),
            'input'      => $a->getInput(),
            'count'      => $a->getCount(),
            'apiCalls'   => $a->getApiCalls(),
            'error'      => $a->getError(),
            'queuedAt'   => $a->getQueuedAt()->getTimestamp() * 1000,
            'startedAt'  => $startedAt !== null ? $startedAt->getTimestamp() * 1000 : null,
            'finishedAt' => $finishedAt !== null ? $finishedAt->getTimestamp() * 1000 : null,
            'duration'   => $duration,
            'attempts'   => $a->getAttempts(),
        ];
    }
}
