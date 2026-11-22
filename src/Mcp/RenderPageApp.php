<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Data\NoSnapshot;
use App\Layout\LayoutSchema;
use App\Layout\Page;
use App\Proof\ComputedProof;
use App\Proof\MeasuredProof;
use App\Ui\PageLink;
use App\Ui\PageLog;
use App\Ui\PageMarkdown;
use App\Ui\PageRenderer;
use App\Ui\Twig\FigureExtension;
use App\Ui\View\PageView;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\RequestContext;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\AI\McpBundle\App\McpAppRenderer;
use Symfony\AI\McpBundle\Attribute\AsMcpApp;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * The page tool: the model sends a layout that names cells, the server resolves every figure and
 * returns the page.
 *
 * - Hosts that show MCP Apps render `structuredContent.html`. The text content then carries no
 *   figure at all, so the model has nothing to restate.
 * - Other hosts get the page as Markdown, where every figure is a link to its proof, and the link
 *   of the whole page: no HTML, which would only weigh on an assistant that reads text.
 *
 * No `toolTemplate`: that path sends the whole HTML as the text content to hosts without app support.
 */
#[AsMcpApp(
    uri: 'ui://go-figure/page',
    name: 'render_page',
    title: 'Go Figure: render a page',
    description: <<<'TXT'
        Renders a page of tiles, charts, tables and notes from official statistics and the datasets added to this server, where every number is read and proven by the server.
        You never write a number: each figure names a catalog cell (dataset id, place code, period), and the server reads its value. Titles, labels and texts contain no digits and no numbers in words; years go in period segments.
        A figure may also be a calculation on cells, computed by the server: a change, a change in percent, a share or a rank (describe_dataset says which a dataset allows). You name the operation and the cells, never the result.
        A cell the server cannot prove is shown as a refusal card, never as a guess.
        TXT,
    template: 'mcp/page.html.twig',
)]
#[WithMonologChannel('go_figure')]
final readonly class RenderPageApp
{
    /** The MCP Apps extension a host declares when it can show the HTML. */
    public const string UI_EXTENSION = 'io.modelcontextprotocol/ui';
    /**
     * Said before the page to a host that reads text: what its assistant may do with the figures. A
     * request, not a guarantee: the server proves the page, not what an assistant writes around it.
     */
    private const string FOR_TEXT = 'The server read every figure below and linked it to its proof. Quote a figure as it is, with its link, and give the link of the page. Add no number of your own: no estimate, no difference, no total. Words that explain what a figure counts are welcome.';

    public function __construct(
        private DenormalizerInterface $denormalizer,
        private PageRenderer $pages,
        private PageMarkdown $markdown,
        private McpAppRenderer $apps,
        private FigureExtension $figures,
        private LoggerInterface $logger,
        private PageLog $log,
        private PageLink $links,
    ) {
    }

    /**
     * @param array<string, mixed> $layout the page: a title and a list of components
     */
    public function render(
        #[Schema(definition: LayoutSchema::DEFINITION)]
        array $layout,
        RequestContext $context,
    ): CallToolResult {
        try {
            $page = $this->denormalizer->denormalize($layout, Page::class);
            $view = $this->pages->view($page);
        } catch (SerializerException $e) {
            $this->logger->warning('render_page refused a layout that does not follow the page schema: {error}', ['error' => $e->getMessage()]);

            return self::error('This layout does not follow the page schema: '.$e->getMessage());
        } catch (NoSnapshot $e) {
            $this->logger->error('render_page cannot prove any figure: {error}', ['error' => $e->getMessage()]);

            return self::error('The server has no data snapshot yet, so no figure can be proven. Ask the operator to build it (make refresh).');
        }

        // A host that shows the page gets no figure in the text, not even a digit: the model has
        // nothing to restate. A text-only host gets the figures as proof links, and the link to the
        // whole page, which opens in any browser.
        $link = $this->links->url($page);
        // Which of the two results a host gets is not seen from outside: said in the log.
        $this->logger->info('render_page answers a host that {shows}', ['shows' => self::showsApps($context) ? 'shows MCP Apps: the page as HTML, no figure in the text' : 'reads text: the figures as Markdown with their proof links']);
        if (self::showsApps($context)) {
            $result = new CallToolResult(
                content: [new TextContent('The page is shown to the user. Do not restate its figures or comment on their values: each figure on the page links to its proof, so point the user to the page instead.')],
                structuredContent: [
                    'html' => $this->apps->renderFragment('page/page.html.twig', ['page' => $view]),
                    'link' => $link,
                    'proofs' => array_map(fn (MeasuredProof|ComputedProof $proof) => [
                        'url' => $this->figures->proofUrl($proof),
                        'fingerprint' => $proof->fingerprint(),
                    ], self::proofs($view)),
                ],
            );
        } else {
            // A host that only reads text gets the text and nothing else: the page as HTML, with its
            // styles, made the result so large that an assistant had to save it to a file and dig
            // the Markdown out. The link opens the page itself in any browser.
            // No structured content at all: a client that finds one hands it to its assistant in
            // place of the text (seen with Claude Code, which then had the link and no figure).
            $result = new CallToolResult(
                content: [new TextContent(self::FOR_TEXT."\n\n".$this->markdown->render($view)."\n[Open the whole page](".$link.")\n")],
            );
        }
        $this->log->shown('render_page', $view);

        return $result;
    }

    private static function showsApps(RequestContext $context): bool
    {
        // Per-request capabilities (stateless era), else those declared at initialize.
        $extensions = $context->getClientCapabilities()?->extensions;
        if (null === $extensions) {
            $declared = (array) $context->getSession()->get('client_capabilities', []);
            $extensions = (array) ($declared['extensions'] ?? []);
        }

        return \array_key_exists(self::UI_EXTENSION, $extensions);
    }

    /**
     * @return list<MeasuredProof|ComputedProof> every proof on the page, in reading order, without duplicates
     */
    private static function proofs(PageView $view): array
    {
        $unique = [];
        foreach ($view->parts() as $figure) {
            if ($figure instanceof MeasuredProof || $figure instanceof ComputedProof) {
                $unique[$figure->fingerprint()] ??= $figure;
            }
        }

        return array_values($unique);
    }

    private static function error(string $message): CallToolResult
    {
        return new CallToolResult(content: [new TextContent($message)], isError: true);
    }
}
