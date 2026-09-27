<?php

declare(strict_types=1);

namespace App\Exception;

final class IdempotencyPayloadMismatchException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Idempotency-Key was already used with a different request payload.');
    }
}
