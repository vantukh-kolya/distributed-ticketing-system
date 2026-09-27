<?php

declare(strict_types=1);

namespace App\Exception;

final class SagaNotFoundException extends \DomainException
{
    public function __construct(string $reservationId)
    {
        parent::__construct(sprintf('Saga for reservation "%s" was not found.', $reservationId));
    }
}
