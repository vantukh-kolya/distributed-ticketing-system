<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SagaQueryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class SagaController extends AbstractController
{
    public function __construct(private readonly SagaQueryService $sagaQueryService)
    {
    }

    #[Route('/api/saga/{reservationId}', name: 'api_saga_show', methods: ['GET'])]
    public function show(string $reservationId): JsonResponse
    {
        return $this->json($this->sagaQueryService->get($reservationId));
    }
}
