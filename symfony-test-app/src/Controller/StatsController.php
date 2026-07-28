<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StatsController extends AbstractController
{
    #[Route('/stats', name: 'stats_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('stats/index.html.twig');
    }

    #[Route('/api/stats', name: 'api_stats', methods: ['GET'])]
    public function stats(ProductRepository $repository): JsonResponse
    {
        return $this->json($repository->getStats());
    }
}
