<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ShowSeatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ShowSeatsController extends AbstractController
{
    public function __construct(private readonly ShowSeatsService $showSeatsService)
    {
    }

    #[Route('/api/shows/{showId}/seats', name: 'api_show_seats', methods: ['GET'])]
    public function __invoke(string $showId): JsonResponse
    {
        return $this->json($this->showSeatsService->get($showId));
    }
}
