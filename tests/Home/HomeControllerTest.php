<?php

declare(strict_types=1);

namespace App\Tests\Home;

use App\Ai\AiSettings;
use App\Layout\LayoutSchema;
use App\Tests\Support\CatalogFixture;
use App\Tests\Support\FactsFixture;
use Mcp\Capability\Discovery\SchemaValidator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The home page shows the idea on a real page before any explanation: a proven figure, a refused
 * cell and a number the model wrote, read and checked like any layout a model sends.
 */
final class HomeControllerTest extends WebTestCase
{
    private const string SHIPPED = __DIR__.'/../../demo/home.json';

    private KernelBrowser $client;
    private string $facts;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        FactsFixture::write($this->facts = self::getContainer()->getParameter('app.facts_path'));
    }

    public function testTheExampleShowsAProvenFigureARefusalAndAnUnsourcedText(): void
    {
        $crawler = $this->client->request('GET', '/how');

        self::assertResponseIsSuccessful();
        self::assertSame(['Proven', 'Missing', 'Unsupported'], $crawler->filter('.outcomes .outcome')->each(static fn ($o) => $o->text()));
        $figure = $crawler->filter('.example .gf-page a.gf-figure')->first();
        self::assertSame('457,644,262', $figure->text());
        self::assertStringStartsWith('http://localhost/proof/', (string) $figure->attr('href'));
        // Italy has no value in the test snapshot, and the United Kingdom is not in the dataset.
        self::assertCount(2, $crawler->filter('.example .gf-page .gf-bars .gf-refusal'));
        self::assertSelectorTextContains('.example .gf-note .gf-unsourced', 'Unsupported: the model wrote this number itself.');
        self::assertAnySelectorTextNotContains('.example', '12%');
        // One main heading: the example's title sits under its section.
        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame('Nights: France, Spain, Italy and the United Kingdom', $crawler->filter('.example h3.gf-title')->text());
    }

    public function testTheExampleShowsAFigureTheServerComputedWithItsProof(): void
    {
        $crawler = $this->client->request('GET', '/how');

        $computed = $crawler->filter('.example .gf-page .gf-kpi a.gf-figure');
        self::assertCount(1, $computed);
        $this->client->request('GET', (string) $computed->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Computed again from the snapshot just now: it matches.');
    }

    public function testThePageOpensOnTheQuestionAndWhatMakesItTrustworthy(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextSame('h1', 'Numbers you can stand behind.');
        self::assertSelectorTextSame('.hero .promise', 'Ask a question. Get figures you can cite, trace and verify.');
        self::assertSelectorTextSame('.hero .mechanism', 'AI writes the page. Every figure is read from the source.');
        self::assertSelectorExists('#ask-form input[name="question"]');
        // The first page is the question; how it works is one click away, in plain sight.
        self::assertSame(['How it works', 'Why it is different', 'Use other data', 'MCP'], $crawler->filter('header.site nav > a')->each(static fn ($a) => $a->text()));
        // Two ways on from the question, in plain sight: how it works, and the data to look at.
        self::assertSame(['/how', '/compose'], $crawler->filter('.hero .hero-links a')->each(static fn ($a) => $a->attr('href')));
        self::assertSame(['See how it works', 'Explore the available data'], $crawler->filter('.hero .hero-links a')->each(static fn ($a) => $a->text()));
        self::assertSelectorNotExists('#why');
        self::assertSelectorNotExists('.example');

        $crawler = $this->client->request('GET', '/how');
        self::assertSelectorTextSame('h1', 'How it works');
        self::assertCount(4, $crawler->filter('.how-steps li'));
        self::assertSelectorExists('#how');
        self::assertSelectorNotExists('#why');

        // Its own page: the same sentence twice, then four lines, limits included.
        $crawler = $this->client->request('GET', '/why');
        self::assertSelectorTextSame('#why h1', 'A source is not a proof');
        // The two sides on one sentence need a cell of the weather: the fixtures of the tests have none.
        self::assertSelectorNotExists('.duel');
        self::assertCount(4, $crawler->filter('#why .versus-row:not(.versus-head)'));
        self::assertSelectorTextContains('#why .why-notes', 'It does not answer on every subject');
        self::assertSelectorNotExists('.destinations');
        self::assertSelectorNotExists('.cards');
    }

    /** Two ways to bring other data, told apart: a file of one's own, or a public source. */
    public function testThePageOfOtherDataTellsTheTwoWaysApart(): void
    {
        $crawler = $this->client->request('GET', '/sources');

        self::assertResponseIsSuccessful();
        self::assertSame(['Your own file', 'Public data'], $crawler->filter('.two-ways .way h2')->each(static fn ($h) => $h->text()));
        self::assertSame(['/data', '/import'], $crawler->filter('.two-ways .way a.how-link')->each(static fn ($a) => $a->attr('href')));
        self::assertCount(8, $crawler->filter('.two-ways dt'));

        // The file expected is shown, with an example to download that the page itself accepts.
        $crawler = $this->client->request('GET', '/data');
        self::assertSelectorTextSame('h1', 'Your own file');
        self::assertSame(['shop', 'year', 'sales', 'visitors'], $crawler->filter('.data-sample th')->each(static fn ($th) => $th->text()));
        self::assertSame('/example-shops.csv', $crawler->filter('.data-sample a[download]')->attr('href'));
        self::assertSelectorTextContains('.data-format', 'Up to 256 KB, 5000 lines and 200 places.');
        self::assertSelectorTextSame('.data-drop .file-pick', 'Choose a CSV file');
    }

    public function testWithAKeyThePageSaysWhichModelAnswersWithoutShowingTheKey(): void
    {
        self::getContainer()->set(AiSettings::class, AiSettings::fromEnv('anthropic', '', 'sk-ant-secret'));
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.hero .mode.on', 'Answered by claude-sonnet-5-5 (anthropic).');
        self::assertStringNotContainsString('sk-ant-secret', (string) $this->client->getResponse()->getContent());
    }

    public function testWithoutASnapshotTheHomePageStillOpensWithoutTheExample(): void
    {
        unlink($this->facts);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.example');
        self::assertSelectorExists('#ask-form');
    }

    /**
     * The shipped example follows the page schema, names cells of the shipped catalog, except the
     * place the data does not have (Iceland, refused on purpose), and holds a number in the model's text, hidden on purpose.
     */
    public function testTheShippedExampleNamesCellsOfTheShippedCatalog(): void
    {
        $layout = json_decode((string) file_get_contents(self::SHIPPED), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([], new SchemaValidator()->validateAgainstJsonSchema($layout, LayoutSchema::DEFINITION));
        self::assertFileExists(self::getContainer()->getParameter('app.home_layout'));

        $catalog = CatalogFixture::loader()->load(__DIR__.'/../../catalog');
        $outside = [];
        $texts = [];
        $walk = static function (mixed $node) use (&$walk, &$outside, &$texts, $catalog): void {
            if (!\is_array($node)) {
                return;
            }
            if (\is_string($node['dataset'] ?? null) && \is_string($node['geo'] ?? null) && \is_string($node['time'] ?? null)) {
                $entry = $catalog[$node['dataset']] ?? null;
                if (null === $entry || !$entry->covers($node['geo']) || !\in_array($node['time'], $entry->time->periods(), true)) {
                    $outside[] = $node['geo'].' '.$node['time'];
                }
            }
            if ('text' === ($node['kind'] ?? null) && \is_string($node['text'] ?? null)) {
                $texts[] = $node['text'];
            }
            array_map($walk, $node);
        };
        $walk($layout);

        self::assertSame(['IS M09'], $outside);
        self::assertNotEmpty(preg_grep('/\d/', $texts), 'A number in the model\'s text, hidden on purpose.');
    }
}
