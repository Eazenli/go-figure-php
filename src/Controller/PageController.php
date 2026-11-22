<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\NoSnapshot;
use App\Layout\LayoutSchema;
use App\Layout\Page;
use App\Ui\PageLink;
use App\Ui\PageLog;
use App\Ui\PageRenderer;
use Mcp\Capability\Discovery\SchemaValidator;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * A whole page from its link (/p/{token}): for hosts that cannot show the MCP App, for /ask, and
 * for sharing. The layout goes through the same schema check as an MCP call, then every figure is
 * read and proven again.
 */
#[WithMonologChannel('go_figure')]
final class PageController extends AbstractController
{
    private SchemaValidator $schema;

    public function __construct(
        private readonly DenormalizerInterface $denormalizer,
        private readonly PageRenderer $pages,
        private readonly PageLog $log,
        private readonly LoggerInterface $logger,
    ) {
        $this->schema = new SchemaValidator();
    }

    #[Route('/p/{token}', name: 'page', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function show(string $token): Response
    {
        $layout = PageLink::decode($token);
        if (null === $layout || [] !== $this->schema->validateAgainstJsonSchema($layout, LayoutSchema::DEFINITION)) {
            return $this->render('p.html.twig', ['problem' => 'This link does not hold a page.'], new Response(status: Response::HTTP_NOT_FOUND));
        }

        try {
            $view = $this->pages->view($this->denormalizer->denormalize($layout, Page::class));
        } catch (SerializerException) {
            return $this->render('p.html.twig', ['problem' => 'This link does not hold a page.'], new Response(status: Response::HTTP_NOT_FOUND));
        } catch (NoSnapshot $e) {
            $this->logger->error('A page link cannot prove any figure: {error}', ['error' => $e->getMessage()]);

            return $this->render('p.html.twig', ['problem' => 'The server has no data snapshot yet, so no figure can be proven.']);
        }
        $this->log->shown('page link', $view);

        return $this->render('p.html.twig', ['page' => $view, 'token' => $token]);
    }
}
