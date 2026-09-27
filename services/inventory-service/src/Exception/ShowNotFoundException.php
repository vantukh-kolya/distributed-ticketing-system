<?php

declare(strict_types=1);

namespace App\Exception;

final class ShowNotFoundException extends \DomainException
{
    public function __construct(string $showId)
    {
        parent::__construct(sprintf('Show "%s" was not found.', $showId));
    }
}
