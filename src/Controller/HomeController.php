<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The page of the site is the question (AskController); this is what the container asks.
 */
final class HomeController
{
    /** Used by the container healthcheck. */
    #[Route('/healthz', name: 'healthz', methods: ['GET'])]
    public function health(): Response
    {
        return new Response('ok', headers: ['Content-Type' => 'text/plain']);
    }
}
