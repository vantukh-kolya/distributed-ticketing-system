<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Seat;
use App\Enum\SeatLockResult;
use App\Repository\SeatRepository;

/**
 * Locks seat rows and applies seat-level state transitions.
 * Must run inside a caller-managed transaction.
 */
final readonly class SeatLockingService
{
    public function __construct(
        private SeatRepository $seatRepository,
    ) {
    }

    /**
     * @param list<string> $seatIds
     */
    public function attemptHold(string $showId, string $reservationId, array $seatIds): SeatLockResult
    {
        $sortedSeatIds = $this->normalizeSeatIds($seatIds);
        if ($sortedSeatIds === []) {
            return SeatLockResult::SeatsNotFound;
        }

        $seats = $this->seatRepository->findForUpdateByShowAndIds($showId, $sortedSeatIds);

        if (!$this->matchesRequestedSeats($seats, $sortedSeatIds)) {
            return SeatLockResult::SeatsNotFound;
        }

        if (!$this->allSeatsAvailable($seats)) {
            return SeatLockResult::NotAllAvailable;
        }

        foreach ($seats as $seat) {
            $seat->holdFor($reservationId);
        }

        return SeatLockResult::Success;
    }

    /**
     * @param list<string> $seatIds
     */
    public function attemptRelease(string $showId, string $reservationId, array $seatIds): SeatLockResult
    {
        $sortedSeatIds = $this->normalizeSeatIds($seatIds);
        if ($sortedSeatIds === []) {
            return SeatLockResult::SeatsNotFound;
        }

        $seats = $this->seatRepository->findForUpdateByShowAndIds($showId, $sortedSeatIds);

        if (!$this->matchesRequestedSeats($seats, $sortedSeatIds)) {
            return SeatLockResult::SeatsNotFound;
        }

        if (!$this->allSeatsHeldBy($seats, $reservationId)) {
            return SeatLockResult::NotAllHeldByReservation;
        }

        foreach ($seats as $seat) {
            $seat->releaseFor($reservationId);
        }

        return SeatLockResult::Success;
    }

    /**
     * @param list<string> $seatIds
     */
    public function attemptConfirm(string $showId, string $reservationId, array $seatIds): SeatLockResult
    {
        $sortedSeatIds = $this->normalizeSeatIds($seatIds);
        if ($sortedSeatIds === []) {
            return SeatLockResult::SeatsNotFound;
        }

        $seats = $this->seatRepository->findForUpdateByShowAndIds($showId, $sortedSeatIds);

        if (!$this->matchesRequestedSeats($seats, $sortedSeatIds)) {
            return SeatLockResult::SeatsNotFound;
        }

        if (!$this->allSeatsHeldBy($seats, $reservationId)) {
            return SeatLockResult::NotAllHeldByReservation;
        }

        foreach ($seats as $seat) {
            $seat->confirmFor($reservationId);
        }

        return SeatLockResult::Success;
    }

    /**
     * @param list<string> $seatIds
     *
     * @return list<string>
     */
    public function normalizeSeatIds(array $seatIds): array
    {
        $ids = array_values(array_unique($seatIds));
        sort($ids);

        return $ids;
    }

    /**
     * @param list<Seat>             $seats
     * @param non-empty-list<string> $sortedSeatIds
     */
    private function matchesRequestedSeats(array $seats, array $sortedSeatIds): bool
    {
        if (\count($seats) !== \count($sortedSeatIds)) {
            return false;
        }

        foreach ($seats as $index => $seat) {
            if ($seat->getId() !== $sortedSeatIds[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Seat> $seats
     */
    private function allSeatsAvailable(array $seats): bool
    {
        foreach ($seats as $seat) {
            if (!$seat->isAvailable()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Seat> $seats
     */
    private function allSeatsHeldBy(array $seats, string $reservationId): bool
    {
        foreach ($seats as $seat) {
            if (!$seat->isHeldBy($reservationId)) {
                return false;
            }
        }

        return true;
    }
}
