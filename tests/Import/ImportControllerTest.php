<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Ai\AiSettings;
use App\Ai\AskPrompt;
use App\Ai\Platforms;
use App\Catalog\Catalog;
use App\Import\DatasetStructure;
use App\Import\EurostatApi;
use App\Import\Importer;
use App\Import\ImportStore;
use App\Import\ProposalType;
use App\Mcp\ImportTools;
use App\Tests\Support\FactsFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The extended mode (/import): a visitor adds a Eurostat dataset, without a key (search, choose,
 * import) or with one (an agent proposes, the visitor reviews). Eurostat and the model are played by
 * fixtures: no network, no key.
 */
final class ImportControllerTest extends WebTestCase
{
    private const string FIXTURES = __DIR__.'/../fixtures/eurostat/';
    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];

    private KernelBrowser $client;
    /** @var \ArrayObject<int, string> the addresses Eurostat was asked */
    private \ArrayObject $asked;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        FactsFixture::write(self::getContainer()->getParameter('app.facts_path'));
        self::getContainer()->get('cache.rate_limiter')->clear();
        $imported = self::getContainer()->getParameter('app.imported_path');
        if (is_file($imported)) {
            unlink($imported);
        }
        $this->asked = new \ArrayObject();
        $asked = $this->asked;
        self::getContainer()->set(EurostatApi::class, new EurostatApi(new MockHttpClient(static function (string $method, string $url) use ($asked): MockResponse {
            $asked->append($url);

            return match (true) {
                str_contains($url, 'catalogue/toc') => new MockResponse((string) file_get_contents(self::FIXTURES.'toc.txt')),
                str_contains($url, 'data/tour_occ_arnat?') && str_contains($url, 'lastTimePeriod=1') => new MockResponse((string) file_get_contents(self::FIXTURES.'tour_occ_arnat_structure.json')),
                str_contains($url, 'data/tour_occ_arnat?') => new MockResponse((string) file_get_contents(self::FIXTURES.'tour_occ_arnat_data.json')),
                default => new MockResponse('{"error":"not found"}', ['http_code' => 404]),
            };
        }), new ArrayAdapter()));
    }

    public function testASearchListsEachDatasetAboutTravelOnceBestFirst(): void
    {
        $crawler = $this->client->request('GET', '/import?q=arrivals+accommodation');

        self::assertResponseIsSuccessful();
        // Asylum applicants "arrivals" too, but it is not a subject of Go Figure (rule 5).
        self::assertSame(['tour_occ_arnat', 'tour_occ_nin2'], $crawler->filter('.results code')->each(static fn ($c) => $c->text()));
    }

    public function testTheSameChoiceIsNeverReplaced(): void
    {
        $this->client->request('POST', '/import', ['proposal' => self::proposal()], server: self::SAME_ORIGIN);
        self::assertResponseRedirects();

        $this->client->request('POST', '/import', ['proposal' => ['title' => 'Something else'] + self::proposal()], server: self::SAME_ORIGIN);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('ul.problem', 'This choice is already imported, as "Arrivals of foreign tourists".');
    }

    /**
     * A scale in the unit would make a proven figure read a thousand times off: the scale comes from
     * the unit code only, and the unit uses the words of the dataset.
     */
    public function testAUnitWithAScaleOrWordsOfItsOwnIsRefused(): void
    {
        $structure = self::structure();
        $importer = self::getContainer()->get(Importer::class);
        $reasons = static fn (string $unit): array => array_map(static fn (array $r): string => strtr($r[0], $r[1]), $importer->problems(ProposalType::proposal(['unitLabel' => $unit] + self::proposal() + ['theme' => null]), $structure));

        self::assertSame([], $reasons('arrivals'));
        self::assertContains('unit: "k" is a scale: write the unit without it, the server adds the scale of the unit code.', $reasons('k arrivals'));
        self::assertContains('unit: "meur" is a scale: write the unit without it, the server adds the scale of the unit code.', $reasons('MEUR'));
        self::assertContains('unit: use the words of the dataset itself (its title and the codes chosen); "visitors" is not one.', $reasons('visitors'));
    }

    public function testADatasetOffTopicIsRefusedAndOneWithoutAUnitNeedsItsLabel(): void
    {
        $importer = self::getContainer()->get(Importer::class);
        $data = json_decode((string) file_get_contents(self::FIXTURES.'tour_occ_arnat_structure.json'), true, flags: \JSON_THROW_ON_ERROR);
        $offTopic = DatasetStructure::fromJsonStat('migr_asyappctzm', $data);
        unset($data['dimension']['unit']);
        $data['id'] = array_values(array_diff($data['id'], ['unit']));
        $noUnit = DatasetStructure::fromJsonStat('tour_occ_arnat', $data);
        $first = static fn (array $problems): array => array_map(static fn (array $r): string => $r[0], $problems);

        self::assertContains('Go Figure does not add this dataset: it takes the economy, businesses, agriculture, energy, the environment, transport, tourism, prices and culture, and no subject about people\'s lives (health, migration, justice, living conditions).', $first($importer->problems(ProposalType::proposal(['code' => 'migr_asyappctzm'] + self::proposal() + ['theme' => null]), $offTopic)));
        // Without a dimension "unit", the label is the unit: it must be there, in the words of the dataset.
        $problems = $first($importer->problems(ProposalType::proposal(['unitLabel' => ''] + self::proposal() + ['theme' => null]), $noUnit));
        self::assertContains('unit: this dataset has no unit of its own, so write one with the words of its title or of the codes chosen.', $problems);
    }

    /**
     * The STDIO MCP server runs for hours: an import made through the web reaches it all the same.
     */
    public function testAnImportByAnotherProcessIsSeenWithoutRestart(): void
    {
        $catalog = self::getContainer()->get(Catalog::class);
        $before = \count($catalog->all());

        $elsewhere = new ImportStore((string) self::getContainer()->getParameter('app.imported_path'));
        $entry = ['id' => 'x_tour_occ_arnat_abcdef', 'title' => ['en' => 'Arrivals'], 'source' => ['provider' => 'eurostat', 'dataset' => 'tour_occ_arnat', 'url' => 'https://ec.europa.eu/eurostat/databrowser/view/tour_occ_arnat/default/table', 'licence' => 'Eurostat'], 'dimensions' => ['unit' => 'NR'], 'geo' => ['FR'], 'time' => ['granularity' => 'annual', 'from' => '2024', 'to' => '2025'], 'unit' => ['code' => 'NR', 'label' => 'arrivals'], 'scope' => ['en' => 'Arrivals.'], 'theme' => 'crowds', 'operations' => ['rank']];
        self::assertTrue($elsewhere->add($entry, [['x_tour_occ_arnat_abcdef', 'FR', '2025', 'NR', 1.0, '', '2026-10-12', '2026-11-19']], ImportStore::MAX_ENTRIES));

        self::assertCount($before + 1, $catalog->all());
        self::assertFalse($elsewhere->add($entry, [], ImportStore::MAX_ENTRIES), 'Never twice.');
    }

    public function testAnMcpClientAddsADatasetWithTheSameChecks(): void
    {
        $tools = self::getContainer()->get(ImportTools::class);

        $refused = $tools->add('tour_occ_arnat', ['freq=A', 'c_resid=FOR', 'unit=NR', 'nace_r2=I551-I553'], 'Arrivals in twenty twenty-four', 'Arrivals.', 'k arrivals', 'crowds');
        self::assertTrue($refused->isError);

        $added = $tools->add('tour_occ_arnat', ['freq=A', 'c_resid=FOR', 'unit=NR', 'nace_r2=I551-I553'], 'Arrivals of foreign tourists', 'Arrivals of guests living abroad.', 'arrivals', 'crowds');
        self::assertFalse($added->isError);
        self::assertIsArray($added->structuredContent);
        self::assertStringStartsWith('x_tour_occ_arnat_', (string) $added->structuredContent['id']);
    }

    public function testADatasetOffersOneCodePerDimensionWithTheTotalByDefault(): void
    {
        $crawler = $this->client->request('GET', '/import?code=tour_occ_arnat');

        self::assertResponseIsSuccessful();
        $selects = $crawler->filter('select[name^="proposal[dimensions]"]');
        self::assertSame(['proposal[dimensions][freq]', 'proposal[dimensions][c_resid]', 'proposal[dimensions][unit]', 'proposal[dimensions][nace_r2]'], $selects->each(static fn ($s) => $s->attr('name')));
        self::assertSame('TOTAL', $crawler->filter('select[name="proposal[dimensions][c_resid]"] option[selected]')->attr('value'));
        self::assertSame('Arrivals at tourist accommodation establishments', $crawler->filter('#proposal_title')->attr('value'));
        // Geo and time stay free: they are not offered.
        self::assertCount(0, $crawler->filter('select[name*="[geo]"], select[name*="[time]"]'));
    }

    public function testAnImportedDatasetIsDrawnWithEveryFigureProven(): void
    {
        $this->client->request('POST', '/import', ['proposal' => self::proposal()], server: self::SAME_ORIGIN);

        self::assertResponseRedirects();
        $data = array_values(array_filter($this->asked->getArrayCopy(), static fn (string $u): bool => !str_contains($u, 'lastTimePeriod') && str_contains($u, '/data/')))[0];
        self::assertStringContainsString('c_resid=FOR', $data);
        self::assertStringContainsString('&geo=FR', $data, 'Only the places the pages know are asked for.');

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.added h2', 'Added: Arrivals of foreign tourists');
        $id = (string) array_key_last(self::getContainer()->get(Catalog::class)->all());
        self::assertStringStartsWith('x_tour_occ_arnat_', $id);
        $entry = self::getContainer()->get(Catalog::class)->get($id);
        self::assertNotNull($entry);
        // A live question sees it; the example questions keep the catalog they were recorded with.
        self::assertStringContainsString($id, self::getContainer()->get(AskPrompt::class)->system(withImports: true));
        self::assertStringNotContainsString($id, self::getContainer()->get(AskPrompt::class)->system());
        // The United Kingdom is not a place of Go Figure; Spain has a value in one year.
        self::assertSame(['ES', 'FR'], $entry->geo);
        self::assertSame(['2024', '2025'], [$entry->time->from, $entry->time->to]);

        $crawler = $this->client->request('GET', '/compose?'.http_build_query(['dataset' => $id, 'places' => ['FR', 'ES'], 'from' => '2024', 'to' => '2025']));
        self::assertResponseIsSuccessful();
        $figure = $crawler->filter('.gf-bars a.gf-figure')->first();
        self::assertSame('58,740,022', $figure->text());
        self::assertSame('arrivals', $crawler->filter('.gf-bars .gf-unit')->first()->text());
        // Confidential at the source: refused, never guessed.
        self::assertStringContainsString('Missing: The source publishes no value for this place and period.', $crawler->filter('.gf-bars')->first()->text());

        $this->client->request('GET', (string) $figure->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.match', 'This figure matches the snapshot it was read from.');
        self::assertAnySelectorTextContains('dd', 'tour_occ_arnat');
    }

    public function testAProposalWithANumberOrAWrongCodeIsRefusedWithEveryReasonAndNothingKept(): void
    {
        $proposal = ['title' => 'Arrivals in twenty twenty-four', 'dimensions' => ['nace_r2' => 'XYZ'] + self::proposal()['dimensions']] + self::proposal();
        $this->client->request('POST', '/import', ['proposal' => $proposal], server: self::SAME_ORIGIN);

        self::assertResponseStatusCodeSame(422);
        $problems = $this->client->getCrawler()->filter('ul.problem li')->each(static fn ($li) => $li->text());
        self::assertContains('title: Unsupported: the model wrote this number itself.', $problems);
        // A code the form does not offer is dropped: the dimension is then said to be missing.
        self::assertContains('Choose one code for the dimension nace_r2 (Statistical classification of economic activities): only the place and the period may vary.', $problems, implode(' / ', $problems));
        self::assertFileDoesNotExist((string) self::getContainer()->getParameter('app.imported_path'));
    }

    public function testAPostFromAnotherSiteDownloadsNothing(): void
    {
        $this->client->request('POST', '/import', ['proposal' => self::proposal()], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->asked);
    }

    public function testAnUnknownCodeIsSaidSo(): void
    {
        $this->client->request('GET', '/import?code=tour_no_such_set');

        self::assertSelectorTextContains('ul.problem', 'Eurostat has no dataset "tour_no_such_set".');
    }

    public function testADatasetOffTopicIsNotEvenRead(): void
    {
        $this->client->request('GET', '/import?code=migr_asyappctzm');

        self::assertSelectorTextContains('ul.problem', 'Go Figure does not add this dataset');
        self::assertCount(0, $this->asked);
    }

    public function testImportsAreLimitedPerVisitor(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->client->request('POST', '/import', ['proposal' => ['code' => 'no_such_set']], server: self::SAME_ORIGIN);
        }
        $this->client->request('POST', '/import', ['proposal' => self::proposal()], server: self::SAME_ORIGIN);

        self::assertResponseStatusCodeSame(429);
    }

    public function testTheAgentNeedsAKey(): void
    {
        $this->client->request('POST', '/import/agent', ['wish' => 'arrivals of foreign tourists'], server: self::SAME_ORIGIN);

        self::assertResponseStatusCodeSame(409);
    }

    /**
     * The agent looks for a dataset, reads its dimensions, then proposes an entry: the visitor gets
     * it to review, nothing is imported yet.
     */
    public function testTheAgentProposesAnEntryForTheVisitorToReview(): void
    {
        $answers = [
            self::toolCall('search_eurostat', ['query' => 'arrivals tourist accommodation']),
            self::toolCall('describe_eurostat_dataset', ['code' => 'tour_occ_arnat']),
            self::content(json_encode([
                'code' => 'tour_occ_arnat',
                'dimensions' => [['dimension' => 'freq', 'code' => 'A'], ['dimension' => 'c_resid', 'code' => 'FOR'], ['dimension' => 'unit', 'code' => 'NR'], ['dimension' => 'nace_r2', 'code' => 'I551']],
                'title' => 'Arrivals of foreign guests in hotels',
                'scope' => 'Guests living abroad arriving at the hotels of the country.',
                'unitLabel' => 'arrivals',
                'theme' => 'crowds',
                'pitfalls' => ['Hotels only.'],
            ], \JSON_THROW_ON_ERROR)),
        ];
        /** @var \ArrayObject<int, string> $sent */
        $sent = new \ArrayObject();
        $model = new MockHttpClient(static function (string $method, string $url, array $options) use (&$answers, $sent): MockResponse {
            $sent->append((string) ($options['body'] ?? ''));

            return new MockResponse((string) array_shift($answers), ['response_headers' => ['content-type' => 'application/json']]);
        });
        $container = self::getContainer();
        $container->set(AiSettings::class, AiSettings::fromEnv('huggingface', '', 'hf_test'));
        $container->set(Platforms::class, new Platforms($container->get('event_dispatcher'), $model));

        $crawler = $this->client->request('POST', '/import/agent', ['wish' => 'arrivals of foreign tourists in hotels'], server: self::SAME_ORIGIN);

        self::assertResponseIsSuccessful();
        self::assertCount(3, $sent);
        // The tools answered the model with codes and titles: the second request carries the search.
        self::assertStringContainsString('tour_occ_arnat: Arrivals at tourist accommodation establishments', (string) $sent[1]);
        self::assertSelectorTextContains('.proposal .note', 'Proposed by openai/gpt-oss-120b');
        self::assertSame('FOR', $crawler->filter('select[name="proposal[dimensions][c_resid]"] option[selected]')->attr('value'));
        self::assertSame('I551', $crawler->filter('select[name="proposal[dimensions][nace_r2]"] option[selected]')->attr('value'));
        self::assertSame('Arrivals of foreign guests in hotels', $crawler->filter('#proposal_title')->attr('value'));
        self::assertFileDoesNotExist((string) $container->getParameter('app.imported_path'));
    }

    private static function structure(): DatasetStructure
    {
        return DatasetStructure::fromJsonStat('tour_occ_arnat', json_decode((string) file_get_contents(self::FIXTURES.'tour_occ_arnat_structure.json'), true, flags: \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function proposal(): array
    {
        return [
            'code' => 'tour_occ_arnat',
            'dimensions' => ['freq' => 'A', 'c_resid' => 'FOR', 'unit' => 'NR', 'nace_r2' => 'I551-I553'],
            'title' => 'Arrivals of foreign tourists',
            'scope' => 'Arrivals of guests living abroad at all tourist accommodation of the country.',
            'unitLabel' => 'arrivals',
            'theme' => 'crowds',
            'pitfalls' => "An arrival is a stay, not a person.\n",
        ];
    }

    /**
     * @param array<string, string> $arguments
     */
    private static function toolCall(string $name, array $arguments): string
    {
        return json_encode(['id' => 'x', 'object' => 'chat.completion', 'choices' => [['index' => 0, 'finish_reason' => 'tool_calls', 'message' => [
            'role' => 'assistant', 'content' => null,
            'tool_calls' => [['id' => 'call_'.$name, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($arguments, \JSON_THROW_ON_ERROR)]]],
        ]]]], \JSON_THROW_ON_ERROR);
    }

    private static function content(string $content): string
    {
        return json_encode(['id' => 'x', 'object' => 'chat.completion', 'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $content]]]], \JSON_THROW_ON_ERROR);
    }
}
