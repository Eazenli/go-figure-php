<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Layout\LayoutSchema;
use App\Mcp\RenderPageApp;
use App\Tests\Support\FactsFixture;
use App\Tests\Support\McpRequests;
use Monolog\Handler\TestHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * render_page as an MCP host calls it: with a layout written by hand (the "frozen layout" of the
 * go/no-go), once from a host that shows MCP Apps and once from a text-only host.
 */
final class RenderPageTest extends WebTestCase
{
    use McpRequests;

    private const string LAYOUT = <<<'JSON'
        {
            "title": "Nights in France and Spain",
            "components": [
                {"kind": "kpi", "label": "Nights in France", "figure": {"type": "measured", "dataset": "nights_total", "geo": "FR", "time": "2024"}},
                {"kind": "bar", "title": "Nights by country", "bars": [
                    {"type": "measured", "dataset": "nights_total", "geo": "FR", "time": "2024"},
                    {"type": "measured", "dataset": "nights_total", "geo": "ES", "time": "2024"},
                    {"type": "measured", "dataset": "nights_total", "geo": "DE", "time": "2024"}
                ]},
                {"kind": "line", "title": "Nights over time", "series": [
                    {"dataset": "nights_total", "geo": "FR", "from": "2019", "to": "2024"}
                ]},
                {"kind": "table", "title": "Side by side", "rows": [
                    {"type": "measured", "dataset": "nights_total", "geo": "FR", "time": "2020"},
                    {"type": "measured", "dataset": "nights_total", "geo": "MT", "time": "2025"}
                ]},
                {"kind": "note", "segments": [
                    {"kind": "text", "text": "France counted "},
                    {"kind": "figure", "figure": {"type": "measured", "dataset": "nights_total", "geo": "FR", "time": "2024"}},
                    {"kind": "text", "text": " nights in "},
                    {"kind": "period", "from": "2024", "to": "2024"},
                    {"kind": "text", "text": ", up 99% since [the crisis](https://example.org)"}
                ]}
            ]
        }
        JSON;

    private const array APPS = ['extensions' => [RenderPageApp::UI_EXTENSION => ['mimeTypes' => ['text/html;profile=mcp-app']]]];

    private KernelBrowser $client;
    private string $facts;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $path = self::getContainer()->getParameter('app.facts_path');
        FactsFixture::write($this->facts = $path);
    }

    public function testTheToolTakesTheLayoutSchemaAndPointsToItsApp(): void
    {
        $session = $this->initialize($this->client, self::APPS);
        $tools = $this->rpc($this->client, $session, 'tools/list')['result']['tools'];
        $tool = array_values(array_filter($tools, static fn (array $t) => 'render_page' === $t['name']))[0] ?? null;

        self::assertNotNull($tool);
        self::assertSame('ui://go-figure/page', $tool['_meta']['ui']['resourceUri']);
        self::assertSame(['layout'], array_keys($tool['inputSchema']['properties']), 'The request context is injected, not asked from the model.');
        self::assertSame(['layout'], $tool['inputSchema']['required']);
        self::assertSame(
            json_decode(json_encode(LayoutSchema::DEFINITION, \JSON_THROW_ON_ERROR), true),
            $tool['inputSchema']['properties']['layout'],
        );
    }

    public function testATextOnlyHostGetsMarkdownWhereEveryNumberIsAProofLink(): void
    {
        $result = $this->call($this->initialize($this->client));
        $text = $result['content'][0]['text'];

        self::assertFalse($result['isError'] ?? false);
        // First what an assistant may do with the figures, then the page.
        self::assertStringStartsWith('The server read every figure below and linked it to its proof. Quote a figure as it is, with its link, and give the link of the page. Add no number of your own', $text);
        self::assertStringContainsString("\n\n# Nights in France and Spain\n\n## Nights in France\n\n- France `FR`, `2024`: [457,644,262]", $text);
        // A text host gets the text and nothing else: a client that finds structured content hands
        // it to its assistant in place of the text, figures and all.
        self::assertArrayNotHasKey('structuredContent', $result);
        self::assertMatchesRegularExpression('#\[Open the whole page\]\(http://localhost/p/[A-Za-z0-9_-]+\)#', $text);
        self::assertMatchesRegularExpression('#\[457,644,262\]\(http://localhost/proof/[A-Za-z0-9_-]+\)#', $text);
        self::assertMatchesRegularExpression('#\[257,875,315\]\(http://localhost/proof/[A-Za-z0-9_-]+\) nights \(estimated\)#', $text);
        self::assertStringContainsString('*Missing: This source has no data for this place.* (asked for `nights_total, DE, 2024`)', $text);
        self::assertStringContainsString('*Missing: The source publishes no value for this place and period.*', $text);
        self::assertStringContainsString('**Unsupported: the model wrote this number itself.**', $text);

        // What was refused leaves a trail in the logs: what the model asked for or wrote, and why.
        $logs = $this->logs();
        self::assertTrue($logs->hasInfoThatContains('render_page refused a figure: place_not_covered'));
        self::assertTrue($logs->hasInfoThatContains('render_page refused a figure: value_missing'));
        self::assertTrue($logs->hasInfoThatContains('render_page did not show a text: Unsupported: the model wrote this number itself.'));
        self::assertTrue($logs->hasInfoThatContains('render_page showed a page (proven figures: 9, refused: 2, texts or periods not shown: 1)'));

        // Outside proof links and code spans (places, periods), the text holds no digit at all.
        $rest = (string) preg_replace(['#\[[0-9,.]+(?: °C| mm|%)?\]\(http://localhost/proof/[A-Za-z0-9_-]+\)#', '/`[^`]*`/', '#\[Open the whole page\]\(http://localhost/p/[A-Za-z0-9_-]+\)#'], '', $text);
        self::assertDoesNotMatchRegularExpression('/\d/', $rest, $rest);

        // Each link opens a proof that matches the snapshot.
        preg_match('#\(http://localhost(/proof/[A-Za-z0-9_-]+)\)#', $text, $link);
        $this->client->request('GET', $link[1] ?? self::fail('No proof link in the text.'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.match', 'matches the snapshot');
    }

    public function testACityFigureComesWithWhatItsSourceAsksToShow(): void
    {
        $layout = ['title' => 'Warm afternoons', 'components' => [['kind' => 'bar', 'title' => 'In September', 'bars' => [
            ['type' => 'measured', 'dataset' => 'temp_max_city', 'geo' => 'ES004C', 'time' => 'M09'],
            ['type' => 'measured', 'dataset' => 'temp_max_city', 'geo' => 'PT001C', 'time' => 'M09'],
        ]]]];

        $text = $this->call($this->initialize($this->client), $layout)['content'][0]['text'];
        // The text names the city as the HTML does, next to its code.
        self::assertMatchesRegularExpression('#- Seville `ES004C`, `M09`: \[31\.1 °C\]\(http://localhost/proof/#', $text);
        self::assertStringContainsString('Sources: [Weather data by Open-Meteo\.com](https://open-meteo.com/); Contains modified Copernicus Climate Change Service information.', $text);

        $html = $this->call($this->initialize($this->client, self::APPS), $layout)['structuredContent']['html'];
        self::assertStringContainsString('<p class="gf-credits">Sources: <a href="https://open-meteo.com/" rel="noopener noreferrer">Weather data by Open-Meteo.com</a>; Contains modified Copernicus Climate Change Service information.</p>', $html);
    }

    public function testAPageWithoutSuchASourceHasNoCredits(): void
    {
        $result = $this->call($this->initialize($this->client, self::APPS));

        self::assertStringNotContainsString('class="gf-credits"', $result['structuredContent']['html']);
    }

    public function testALayoutOutsideTheSchemaIsRefusedAndLogged(): void
    {
        $response = $this->rpc($this->client, $this->initialize($this->client), 'tools/call', [
            'name' => 'render_page',
            'arguments' => ['layout' => ['title' => 'Nights in France']],
        ]);

        // The SDK refuses it against the input schema, before the tool runs.
        self::assertSame(-32602, $response['error']['code'] ?? null, json_encode($response, \JSON_THROW_ON_ERROR));
        self::assertTrue($this->logs()->hasWarningThatContains('Call to render_page refused: Invalid parameters for tool \'render_page\': Property \'/layout\': Missing required properties: `components`.'));
    }

    public function testModelTextCannotAddALinkOrANumeral(): void
    {
        // "&#xBD;" holds no digit, but a Markdown host would show it as ½.
        $layout = ['title' => 'A [trap](the page), up &#xBD;', 'components' => []];
        $text = $this->call($this->initialize($this->client), $layout)['content'][0]['text'];

        self::assertStringContainsString('A \[trap\](the page), up \&\#xBD;', $text);
    }

    public function testAnAddressWrittenByTheModelDoesNotBecomeALink(): void
    {
        // Hosts turn these into links on their own; the only links of a page are its proofs. An
        // address is refused at the source, and a bare domain name is escaped.
        $initialized = $this->initialize($this->client);
        $refused = $this->call($initialized, ['title' => 'Book at https://evil.example or www.evil.example', 'components' => []])['content'][0]['text'];
        $escaped = $this->call($initialized, ['title' => 'See evil.example. Thanks.', 'components' => []])['content'][0]['text'];

        self::assertStringContainsString("\n\n# **Refused: web addresses are not allowed.**", $refused);
        self::assertStringNotContainsString('evil', $refused);
        self::assertStringContainsString('See evil\.example. Thanks.', $escaped);
    }

    public function testTheTextFallbackKeepsFiguresAndPeriodsApartAndRefusesInvisibleCharacters(): void
    {
        $layout = ['title' => 'Nights', 'components' => [['kind' => 'note', 'segments' => [
            ['kind' => 'text', 'text' => 'From'],
            ['kind' => 'period', 'from' => '2019', 'to' => '2019'],
            ['kind' => 'period', 'from' => '2024', 'to' => '2024'],
            ['kind' => 'text', 'text' => "France counted \u{202E}"],
            ['kind' => 'figure', 'figure' => ['type' => 'measured', 'dataset' => 'nights_total', 'geo' => 'FR', 'time' => '2024']],
        ]]]];

        $text = $this->call($this->initialize($this->client), $layout)['content'][0]['text'];

        self::assertStringContainsString('From `2019` `2024`', $text);
        self::assertStringContainsString('**Refused: invisible formatting characters are not allowed.**[457,644,262](http://localhost/proof/', $text);
        self::assertStringNotContainsString("\u{202E}", $text);
    }

    public function testAHostThatShowsTheAppGetsThePageAndNoFigureInTheText(): void
    {
        $result = $this->call($this->initialize($this->client, self::APPS));

        self::assertDoesNotMatchRegularExpression('/\d/', $result['content'][0]['text']);
        self::assertStringContainsString('class="gf-figure"', $result['structuredContent']['html']);
        self::assertStringContainsString('Missing: This source has no data for this place.', $result['structuredContent']['html']);

        $proofs = $result['structuredContent']['proofs'];
        self::assertCount(5, $proofs, 'A figure shown twice is one proof.');
        foreach ($proofs as $proof) {
            self::assertStringStartsWith('http://localhost/proof/', $proof['url']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $proof['fingerprint']);
        }
    }

    public function testWithoutASnapshotTheToolSaysSoInsteadOfAPage(): void
    {
        unlink($this->facts);

        $result = $this->call($this->initialize($this->client));

        self::assertTrue($result['isError']);
        self::assertStringContainsString('no data snapshot yet', $result['content'][0]['text']);
        self::assertTrue($this->logs()->hasErrorThatContains('render_page cannot prove any figure: No data snapshot at'));
    }

    public function testTheAppShellOpensProofLinksThroughTheHost(): void
    {
        $session = $this->initialize($this->client, self::APPS);
        $html = $this->rpc($this->client, $session, 'resources/read', ['uri' => 'ui://go-figure/page'])['result']['contents'][0]['text'];

        self::assertStringContainsString('<div id="root">', $html);
        self::assertStringContainsString("openLink(link.getAttribute('href'))", $html);
    }

    /** What the app logged while serving the last request (the "go_figure" channel). */
    private function logs(): TestHandler
    {
        return $this->client->getContainer()->get('monolog.handler.go_figure');
    }

    /**
     * @param array<string, mixed>|null $layout
     *
     * @return array<string, mixed> the tool result
     */
    private function call(string $session, ?array $layout = null): array
    {
        $response = $this->rpc($this->client, $session, 'tools/call', [
            'name' => 'render_page',
            'arguments' => ['layout' => $layout ?? json_decode(self::LAYOUT, true, flags: \JSON_THROW_ON_ERROR)],
        ]);
        self::assertArrayHasKey('result', $response, json_encode($response, \JSON_THROW_ON_ERROR));

        return $response['result'];
    }
}
