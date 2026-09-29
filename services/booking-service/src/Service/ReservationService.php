<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CreateReservationRequest;
use App\Dto\ReservationResponse;
use App\Entity\Reservation;
use App\Exception\IdempotencyPayloadMismatchException;
use App\Mapper\ReservationMapper;
use App\Repository\ReservationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Ticketing\Contracts\Event\ReservationRequested;
use Ticketing\Outbox\OutboxRecorder;

final readonly class ReservationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReservationRepository $reservationRepository,
        private ReservationMapper $reservationMapper,
        private OutboxRecorder $outboxRecorder,
    ) {
    }

    public function create(CreateReservationRequest $request, string $idempotencyKey): ReservationResponse
    {
        $existing = $this->reservationRepository->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $this->resolveDuplicate($existing, $request);
        }

        try {
            return $this->entityManager->wrapInTransaction(function () use ($request, $idempotencyKey): ReservationResponse {
                $raceCheck = $this->reservationRepository->findByIdempotencyKey($idempotencyKey);
                if ($raceCheck !== null) {
                    return $this->resolveDuplicate($raceCheck, $request);
                }

                $reservation = new Reservation(
                    id: Uuid::v7()->toRfc4122(),
                    idempotencyKey: $idempotencyKey,
                    correlationId: Uuid::v7()->toRfc4122(),
                    showId: $request->showId,
                    seatIds: $request->seatIds,
                    buyerName: $request->buyerName,
                    buyerEmail: $request->buyerEmail,
                );

                $this->entityManager->persist($reservation);

                $this->outboxRecorder->record(
                    new ReservationRequested(
                        reservationId: $reservation->getId(),
                        showId: $reservation->getShowId(),
                        seatIds: $reservation->getSeatIds(),
                        correlationId: $reservation->getCorrelationId(),
                    ),
                    reservationId: $reservation->getId(),
                    correlationId: $reservation->getCorrelationId(),
                );

                return $this->reservationMapper->toResponse($reservation);
            });
        } catch (UniqueConstraintViolationException) {
            $this->entityManager->clear();
            $existing = $this->reservationRepository->findByIdempotencyKey($idempotencyKey);
            if ($existing === null) {
                throw new \RuntimeException('Idempotency conflict without stored reservation.');
            }

            return $this->resolveDuplicate($existing, $request);
        }
    }

    private function resolveDuplicate(Reservation $existing, CreateReservationRequest $request): ReservationResponse
    {
        if (!$this->payloadMatches($existing, $request)) {
            throw new IdempotencyPayloadMismatchException();
        }

        return $this->reservationMapper->toResponse($existing);
    }

    private function payloadMatches(Reservation $existing, CreateReservationRequest $request): bool
    {
        $storedSeats = $existing->getSeatIds();
        $incomingSeats = array_values(array_unique($request->seatIds));
        sort($storedSeats);
        sort($incomingSeats);

        return $existing->getShowId() === $request->showId
            && $storedSeats === $incomingSeats
            && $existing->getBuyerName() === $request->buyerName
            && $existing->getBuyerEmail() === $request->buyerEmail;
    }
}
