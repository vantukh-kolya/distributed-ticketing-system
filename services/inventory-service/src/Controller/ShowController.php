<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ShowCatalogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ShowController extends AbstractController
{
    public function __construct(private readonly ShowCatalogService $showCatalogService)
    {
    }

    #[Route('/api/shows', name: 'api_shows_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json($this->showCatalogService->list());
    }
}
