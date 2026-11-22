<?php

declare(strict_types=1);

namespace App\Tests\Composer;

use App\Tests\Support\FactsFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The composer without AI, as a visitor uses it (go/no-go criterion 5: three combinations of
 * indicator, places and periods).
 */
final class ComposeControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $facts;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $path = self::getContainer()->getParameter('app.facts_path');
        FactsFixture::write($this->facts = $path);
    }

    public function testWithoutASnapshotThePageSaysSoAndTheErrorIsLogged(): void
    {
        unlink($this->facts);

        $this->client->request('GET', '/compose?'.http_build_query(['dataset' => 'nights_total', 'places' => ['FR'], 'from' => '2024', 'to' => '2024']));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.problem', 'no data snapshot yet');
        self::assertSelectorNotExists('.gf-page');
        self::assertTrue($this->client->getContainer()->get('monolog.handler.go_figure')->hasErrorThatContains('The composer cannot prove any figure: No data snapshot at'));
    }

    public function testTheFirstVisitShowsTheFormOnly(): void
    {
        $this->client->request('GET', '/compose');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form.gf-compose select[name="dataset"]');
        self::assertSelectorNotExists('.gf-page');
        self::assertSelectorNotExists('.form-error-message, ul li');
        // The periods are those of the first indicator.
        $periods = $this->client->getCrawler()->filter('select[name="from"] option')->each(static fn ($o) => $o->attr('value'));
        self::assertSame(['2019', '2020', '2021', '2022', '2023', '2024', '2025'], $periods);
        self::assertSame('2025', $this->client->getCrawler()->filter('select[name="to"] option[selected]')->attr('value'), 'The whole range by default.');
    }

    public function testATrackingParameterDoesNotBreakASharedLink(): void
    {
        $this->client->request('GET', '/compose?utm_source=newsletter');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[aria-invalid="true"]');

        $this->client->request('GET', '/compose?'.http_build_query(['dataset' => 'nights_total', 'places' => ['FR'], 'from' => '2019', 'to' => '2024', 'utm_source' => 'newsletter']));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.gf-page');
    }

    public function testSwitchingIndicatorKeepsThePageWorking(): void
    {
        // The period lists still hold the years of the previous indicator: no JS refreshed them.
        $crawler = $this->client->request('GET', '/compose?'.http_build_query(['dataset' => 'temp_max', 'places' => ['FR'], 'from' => '2019', 'to' => '2025']));

        self::assertResponseIsSuccessful();
        self::assertSame('M01', $crawler->filter('select[name="from"] option[selected]')->attr('value'));
        self::assertSame('M12', $crawler->filter('select[name="to"] option[selected]')->attr('value'));
        self::assertSelectorTextContains('.gf-page .gf-title', 'Average daily maximum temperature');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>, int}>
     */
    public static function combinations(): iterable
    {
        yield 'one place over time' => [['dataset' => 'nights_total', 'places' => ['FR'], 'from' => '2019', 'to' => '2024'], ['457,644,262', '446,553,868'], 0];
        yield 'two places, one year' => [['dataset' => 'nights_total', 'places' => ['FR', 'ES'], 'from' => '2024', 'to' => '2024'], ['457,644,262', '505,166,012'], 0];
        // ES has no 2025 value, MT none before 2025 and a blank 2025: bars, line and table say so.
        yield 'missing cells are refused' => [['dataset' => 'nights_total', 'places' => ['ES', 'MT'], 'from' => '2019', 'to' => '2025'], ['469,813,551', '505,166,012'], 6];
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string>         $figures values that must be on the page, each as a proof link
     */
    #[DataProvider('combinations')]
    public function testACombinationGivesAPageOfProvenFigures(array $query, array $figures, int $refusals): void
    {
        $crawler = $this->client->request('GET', '/compose?'.http_build_query($query));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.gf-page .gf-title', 'Nights spent in tourist accommodation');
        $shown = $crawler->filter('.gf-page a.gf-figure')->each(static fn ($a) => $a->text());
        foreach ($figures as $figure) {
            self::assertContains($figure, $shown);
        }
        self::assertCount($refusals, $crawler->filter('.gf-page .gf-refusal'));
        $logs = $this->client->getContainer()->get('monolog.handler.go_figure');
        self::assertTrue($logs->hasInfoThatMatches(\sprintf('/^compose showed a page \(proven figures: [1-9]\d*, refused: %d, texts or periods not shown: 0\)$/', $refusals)));
        // The choices stay selected, so the visitor can adjust them.
        self::assertSame($query['to'], $crawler->filter('select[name="to"] option[selected]')->attr('value'));
    }

    public function testDestinationsAreComparedForAMonthOnTheLatestPeriodTheyAllHave(): void
    {
        $crawler = $this->client->request('GET', '/compare?'.http_build_query(['places' => ['FR', 'ES'], 'month' => '09']));

        self::assertResponseIsSuccessful();
        // Under the page's own title (h1), a second-level heading; then a page per question of the
        // traveller, in the order of the themes, a third-level heading each.
        self::assertSame('Destinations side by side', $crawler->filter('hr + h2')->text());
        self::assertSame(['Will it be crowded?', 'What will the weather be like?'], $crawler->filter('.gf-themes .gf-page .gf-title')->each(static fn ($t) => $t->text()));
        self::assertSame('h3', $crawler->filter('.gf-page .gf-title')->nodeName());
        self::assertSame(['Nights spent in tourist accommodation, whole year 2024', 'Average daily maximum temperature, September, average over many years'], $crawler->filter('.gf-page .gf-heading')->each(static fn ($h) => $h->text()));
        // Annual data: the latest year both have (Malta and the other years are left out).
        self::assertSame(['France', 'Spain'], $crawler->filter('.gf-page .gf-bars .gf-cell')->slice(0, 2)->each(static fn ($c) => $c->text()));
        self::assertSame(['457,644,262', '505,166,012'], $crawler->filter('.gf-page .gf-bars a.gf-figure')->each(static fn ($a) => $a->text()));
        // Under its title, the chart says what it measures; under the chart, its two ends, each the
        // cell itself with its proof.
        self::assertSame('Hotels, holiday homes and campsites; residents and non-residents.', $crawler->filter('.gf-page .gf-scope')->first()->text());
        self::assertSame('Highest: 505,166,012 nights (Spain, 2024). Lowest: 457,644,262 nights (France, 2024).', $crawler->filter('.gf-page .gf-bar-chart + .gf-note')->text());
        // One bar, or a bar without a value: nothing stands out, no sentence.
        self::assertCount(1, $crawler->filter('.gf-page .gf-note'));
        // The climate normal covers France only, and the snapshot has no value for September.
        self::assertSame('Average daily maximum temperature, France, September', $crawler->filter('.gf-page .gf-bars')->eq(1)->filter('.gf-cell')->text());
        self::assertCount(1, $crawler->filter('.gf-page .gf-refusal'));
        // Destinations are buttons to tick, by kind: the chosen ones stay ticked.
        $greece = $crawler->filter('input[name="places[]"][value="EL"]');
        self::assertSame('Greece', $greece->ancestors()->first()->text());
        self::assertSame('Countries', $greece->closest('.gf-chip-group')?->filter('.gf-chip-group-label')->text());
        // Sorted by name: France, then Spain.
        self::assertSame(['FR', 'ES'], $crawler->filter('input[name="places[]"][checked]')->each(static fn ($i) => $i->attr('value')));
        self::assertSame('09', $crawler->filter('input[name="month"][checked]')->attr('value'));
        // No figure here comes from a source that asks for credits.
        self::assertSelectorNotExists('.gf-page .gf-credits');
    }

    public function testACityTakesTheFiguresOfItsCountryWhereTheSourceHasNoneForTheCity(): void
    {
        $crawler = $this->client->request('GET', '/compare?'.http_build_query(['places' => ['ES004C', 'FR'], 'month' => '09']));

        self::assertResponseIsSuccessful();
        self::assertSame([
            // Nights are published by country: Seville gives way to Spain (said by the theme's note).
            'Nights spent in tourist accommodation, whole year 2024',
            // The country normal has France only; the city normal has Seville only.
            'Average daily maximum temperature, September, average over many years',
            'Average daily maximum temperature in the city, September, average over many years',
        ], $crawler->filter('.gf-page .gf-heading')->each(static fn ($h) => $h->text()));
        self::assertSame('Seville takes the figures of its country here: the source publishes them by country.', $crawler->filter('.gf-page .gf-note')->first()->text());
        // In the order of the list (countries, then cities), whatever the order of the address.
        self::assertSame(['France', 'Seville figure for Spain'], $crawler->filter('.gf-page .gf-bars')->eq(0)->filter('.gf-cell')->each(static fn ($c) => $c->text()));
        self::assertSame('Seville, September', $crawler->filter('.gf-page .gf-bars')->eq(2)->filter('.gf-cell')->text());
        self::assertStringStartsWith('31.1', $crawler->filter('.gf-page .gf-bars')->eq(2)->filter('a.gf-figure')->text());
        self::assertSelectorTextSame('.gf-page .gf-credits', 'Sources: Weather data by Open-Meteo.com; Contains modified Copernicus Climate Change Service information.');
        self::assertSelectorExists('.gf-page .gf-credits a[href="https://open-meteo.com/"]');
        // Cities are offered apart from countries, with their country.
        $seville = $crawler->filter('input[name="places[]"][value="ES004C"]');
        self::assertSame('Seville, Spain', $seville->ancestors()->first()->text());
        self::assertSame('Cities', $seville->closest('.gf-chip-group')?->filter('.gf-chip-group-label')->text());
        self::assertSame('Countries', $crawler->filter('input[name="places[]"][value="FR"]')->closest('.gf-chip-group')?->filter('.gf-chip-group-label')->text());
    }

    public function testACityChosenWithItsCountryIsNotLeftOutWithoutAWord(): void
    {
        foreach ([['ES', 'ES004C'], ['ES004C', 'ES']] as $places) {
            $crawler = $this->client->request('GET', '/compare?'.http_build_query(['places' => $places, 'month' => '09']));

            self::assertResponseIsSuccessful();
            self::assertSame('Nights spent in tourist accommodation, whole year', $crawler->filter('.gf-page .gf-heading')->first()->text());
            self::assertStringStartsWith('Seville takes the figures of its country here', $crawler->filter('.gf-page .gf-note')->first()->text());
            self::assertSame(['Spain, 2024'], $crawler->filter('.gf-page .gf-bars')->eq(0)->filter('.gf-cell')->each(static fn ($c) => $c->text()));
        }
    }

    public function testAFirstVisitShowsAnExampleAlreadyDrawn(): void
    {
        $crawler = $this->client->request('GET', '/compare');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.hint', 'An example to start with');
        // The test catalog has two of the example cities: Seville and Lisbon, in September.
        self::assertSame(['PT001C', 'ES004C'], $crawler->filter('input[name="places[]"][checked]')->each(static fn ($i) => $i->attr('value')), 'Lisbon, then Seville: sorted by name.');
        self::assertSame('September', $crawler->filter('input[name="month"][checked]')->attr('aria-label'));
        self::assertSame('Destinations side by side', $crawler->filter('hr + h2')->text());
        self::assertSame(['Seville', 'Lisbon'], $crawler->filter('.gf-page .gf-bars')->last()->filter('.gf-cell')->each(static fn ($c) => $c->text()));
        // Logged apart from a visitor's own choice.
        $logs = $this->client->getContainer()->get('monolog.handler.go_figure');
        self::assertTrue($logs->hasInfoThatContains('compare-example showed a page'));
        self::assertFalse($logs->hasInfoThatContains('compare showed a page'));
    }

    public function testAWrongChoiceNeverShowsTheExample(): void
    {
        $crawler = $this->client->request('GET', '/compare?'.http_build_query(['places' => 'ES', 'month' => '09']));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $crawler->filter('input[name="places[]"][checked]'));
        self::assertSelectorNotExists('.hint');
        self::assertSelectorNotExists('.gf-page');
        // The errors and the help are tied to the choices for assistive technologies.
        $fieldset = $crawler->filter('fieldset.gf-field')->first();
        self::assertSame('true', $fieldset->attr('aria-invalid'));
        self::assertStringContainsString('places_help', (string) $fieldset->attr('aria-describedby'));
        self::assertStringContainsString('places_error1', (string) $fieldset->attr('aria-describedby'));
    }

    public function testOneMonthOnly(): void
    {
        $crawler = $this->client->request('GET', '/compare?'.http_build_query(['places' => ['ES', 'FR'], 'month' => ['09', '10']]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Choose one month.');
        self::assertCount(0, $crawler->filter('input[name="month"][checked]'));
        self::assertSelectorNotExists('.gf-page');
    }

    public function testEveryPageOfTheSiteHasItsWaysIn(): void
    {
        $crawler = $this->client->request('GET', '/compare?'.http_build_query(['places' => ['FR', 'ES'], 'month' => '09']));

        self::assertSame(['How it works', 'Why it is different', 'Use other data', 'MCP'], $crawler->filter('header.site nav > a')->each(static fn ($a) => $a->text()));
        self::assertSame(['EN', 'FR'], $crawler->filter('header.site nav .language a')->each(static fn ($a) => $a->text()));
        self::assertSame('EN', $crawler->filter('header.site nav .language a[aria-current]')->text());
        // A banner of its own, outside the main content.
        self::assertCount(0, $crawler->filter('main header.site'));
    }

    public function testAComparisonNeedsTwoPlaces(): void
    {
        $this->client->request('GET', '/compare?'.http_build_query(['places' => ['FR'], 'month' => '09']));

        self::assertSelectorTextContains('form', 'Choose at least two places.');
        self::assertSelectorNotExists('.gf-page');
    }

    public function testPeriodsInTheWrongOrderAreRefusedByTheForm(): void
    {
        $this->client->request('GET', '/compose?'.http_build_query(['dataset' => 'nights_total', 'places' => ['FR'], 'from' => '2024', 'to' => '2019']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'The first period comes after the last one.');
        self::assertSelectorNotExists('.gf-page');
    }

    public function testAnIndicatorOutsideTheCatalogIsRefusedByTheForm(): void
    {
        $this->client->request('GET', '/compose?'.http_build_query(['dataset' => 'nights_hotels', 'places' => ['FR'], 'from' => '2019', 'to' => '2024']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorNotExists('.gf-page');
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function wrongPlaces(): iterable
    {
        yield 'none' => [[], 'Choose at least one place.'];
        yield 'seven' => [['AT', 'EL', 'ES', 'FR', 'IT', 'MT', 'NL'], 'Choose at most 6 places.'];
    }

    public function testAPlaceSentTwiceCountsOnce(): void
    {
        // One checkbox per place: a link that names a place twice ticks it once.
        $crawler = $this->client->request('GET', '/compose?'.http_build_query(['dataset' => 'nights_total', 'places' => ['ES', 'FR', 'ES'], 'from' => '2019', 'to' => '2024']));

        self::assertResponseIsSuccessful();
        self::assertSame(['FR', 'ES'], $crawler->filter('input[name="places[]"][checked]')->each(static fn ($i) => $i->attr('value')));
    }

    /**
     * @param list<string> $places
     */
    #[DataProvider('wrongPlaces')]
    public function testWrongPlacesAreRefusedByTheForm(array $places, string $error): void
    {
        $this->client->request('GET', '/compose?'.http_build_query(['dataset' => 'nights_total', 'places' => $places, 'from' => '2019', 'to' => '2024']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', $error);
        self::assertSelectorNotExists('.gf-page');
    }
}
