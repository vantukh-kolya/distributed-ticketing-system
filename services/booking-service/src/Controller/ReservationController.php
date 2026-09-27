<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateReservationRequest;
use App\Service\ReservationService;
use App\Service\ReservationQueryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ReservationController extends AbstractController
{
    public function __construct(
        private readonly ReservationService $reservationService,
        private readonly ReservationQueryService $reservationQueryService,
    ) {
    }

    #[Route('/api/reservations', name: 'api_reservations_create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] CreateReservationRequest $payload,
        Request $request,
    ): JsonResponse {
        $idempotencyKey = $request->headers->get('Idempotency-Key');
        if ($idempotencyKey === null || $idempotencyKey === '') {
            throw new BadRequestHttpException('Header Idempotency-Key is required.');
        }

        $result = $this->reservationService->create($payload, $idempotencyKey);

        return $this->json($result, Response::HTTP_ACCEPTED);
    }

    #[Route('/api/reservations/{reservationId}', name: 'api_reservations_show', methods: ['GET'])]
    public function show(string $reservationId): JsonResponse
    {
        return $this->json($this->reservationQueryService->get($reservationId));
    }
}
