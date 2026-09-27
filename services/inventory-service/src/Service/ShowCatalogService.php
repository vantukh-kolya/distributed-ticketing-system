<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ShowSummaryResponse;
use App\Mapper\ShowMapper;
use App\Repository\ShowRepository;

final readonly class ShowCatalogService
{
    public function __construct(
        private ShowRepository $showRepository,
        private ShowMapper $showMapper,
    ) {
    }

    /** @return list<ShowSummaryResponse> */
    public function list(): array
    {
        return array_map($this->showMapper->toSummary(...), $this->showRepository->findCatalog());
    }
}
