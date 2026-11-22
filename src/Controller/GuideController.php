<?php

declare(strict_types=1);

namespace App\Controller;

use App\Home\HomeContent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The pages that explain: how an answer is made, and the two ways to bring other data.
 */
final class GuideController extends AbstractController
{
    public function __construct(private readonly HomeContent $home)
    {
    }

    /** One answer taken apart, what is different from a cited source, and the MCP endpoint. */
    #[Route('/how', name: 'how', methods: ['GET'])]
    public function how(Request $request): Response
    {
        return $this->render('how.html.twig', $this->home->view($request->getLocale()));
    }

    /** The difference with an assistant that cites its sources, on one sentence. */
    #[Route('/why', name: 'why', methods: ['GET'])]
    public function why(Request $request): Response
    {
        return $this->render('why.html.twig', $this->home->view($request->getLocale()));
    }

    /** The same pages from an assistant: the endpoint and what comes back. */
    #[Route('/connect', name: 'connect', methods: ['GET'])]
    public function connect(): Response
    {
        return $this->render('mcp.html.twig');
    }

    /** Your own file, or public data: who brings the values, who names them, what the proof says. */
    #[Route('/sources', name: 'sources', methods: ['GET'])]
    public function sources(): Response
    {
        return $this->render('sources.html.twig');
    }
}
