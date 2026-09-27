<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateReservationRequest
{
    /**
     * @param list<string> $seatIds inventory seat UUIDs
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $showId,

        #[Assert\Count(min: 1, minMessage: 'At least one seat is required.')]
        #[Assert\All([
            new Assert\NotBlank(),
            new Assert\Type('string'),
        ])]
        public array $seatIds,

        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $buyerName,

        #[Assert\NotBlank]
        #[Assert\Email]
        public string $buyerEmail,
    ) {
    }
}
