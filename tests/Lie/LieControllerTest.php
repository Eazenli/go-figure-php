<?php

declare(strict_types=1);

namespace App\Tests\Lie;

use App\Controller\LieController;
use App\Layout\LayoutSchema;
use App\Tests\Support\CatalogFixture;
use App\Tests\Support\FactsFixture;
use Mcp\Capability\Discovery\SchemaValidator;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Try to make it lie" as a visitor uses it: the prepared attacks, then layouts of their own.
 */
final class LieControllerTest extends WebTestCase
{
    private const string SHIPPED = __DIR__.'/../../demo/lie';
    private const string NOTE_DIGIT = 'Unsupported: the model wrote this number itself.';
    private const string NOTE_SIGN = 'Unsupported: the model wrote this number sign itself.';
    private const string NOTE_FORMAT = 'Refused: invisible formatting characters are not allowed.';
    private const string NO_PERIOD = 'The data has no such period.';

    private KernelBrowser $client;
    private string $facts;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $path = self::getContainer()->getParameter('app.facts_path');
        FactsFixture::write($this->facts = $path);
    }

    public function testTheVisitorStartsFromAPageWhereEveryFigureIsProven(): void
    {
        $crawler = $this->client->request('GET', '/lie');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"geo": "FR", "time": "2024"', $crawler->filter('textarea[name="layout"]')->text());
        self::assertSelectorTextSame('.tally', 'Figures proven: 4. Figures refused: 0. Texts or periods not shown: 0.');
        self::assertSame('457,644,262', $crawler->filter('#result .gf-kpi .gf-figure')->text());
        self::assertCount(\count(LieController::ATTACKS), $crawler->filter('.gf-attacks a[href^="/lie?attack="]'));
        self::assertTrue($this->logs()->hasInfoThatContains('lie showed a page (proven figures: 4, refused: 0, texts or periods not shown: 0)'));
    }

    public function testANumberSlippedIntoTheTextNeverReachesThePage(): void
    {
        $crawler = $this->client->request('GET', '/lie?attack=digits');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('up 12%', $crawler->filter('textarea')->text());
        self::assertSame([self::NOTE_DIGIT, self::NOTE_DIGIT], $crawler->filter('#result .gf-unsourced')->each(static fn (Crawler $c) => $c->text()));
        // The text, not the HTML: proof links hold digits of their own.
        self::assertStringNotContainsString('12%', $crawler->filter('#result')->text());
        self::assertStringNotContainsString('three countries', $crawler->filter('#result')->text());
        self::assertSelectorTextSame('.tally', 'Figures proven: 4. Figures refused: 0. Texts or periods not shown: 2.');
        self::assertTrue($this->logs()->hasInfoThatContains('lie did not show a text: '.self::NOTE_DIGIT));
    }

    public function testACellTheDataDoesNotHaveIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/lie?attack=cell');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Missing: This source has no data for this place.', 'Missing: This source has no data for this place and period.'],
            $crawler->filter('#result .gf-refusal')->each(static fn (Crawler $c) => $c->text()),
        );
        // The refusal says what was asked for, except a period the data does not have: "2030" is no
        // more a code than "4576".
        self::assertSame('Nights spent in tourist accommodation, UK, 2024', $crawler->filter('#result .gf-kpi .gf-cell')->text());
        self::assertSame('Nights spent in tourist accommodation, France, (a period outside the data)', $crawler->filter('#result .gf-bars li')->last()->filter('.gf-cell')->text());
        self::assertTrue($this->logs()->hasInfoThatContains('lie refused a figure: place_not_covered'));
    }

    public function testACalculationThatMeansNothingIsRefusedNextToOneThatIsProven(): void
    {
        $crawler = $this->client->request('GET', '/lie?attack=compute');

        self::assertResponseIsSuccessful();
        // France from one year to another: computed, and its proof is the one of a calculation.
        $proven = $crawler->filter('#result .gf-kpi')->first()->filter('a.gf-figure');
        self::assertSame('+2.5%', $proven->text());
        $this->client->request('GET', (string) $proven->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'The second value minus the first, in percent of the first.');
        // France then against Spain now: two places, so no change. The refusal says what a change needs.
        self::assertSame(
            ['Refused: Not a change: it needs one place at two periods, from the same dataset.'],
            $crawler->filter('#result .gf-refusal')->each(static fn (Crawler $c) => $c->text()),
        );
    }

    public function testAValueWrittenIntoTheLayoutIsRefusedByTheSchema(): void
    {
        $crawler = $this->client->request('GET', '/lie?attack=value');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('"value": 999000000', $crawler->filter('textarea')->text());
        self::assertSelectorTextContains('#result .problem', 'does not follow the page schema');
        self::assertSame('/components/0/figure: Object contains unexpected additional properties: `value`.', $crawler->filter('#result .errors li')->first()->text());
        self::assertSelectorNotExists('.gf-page');
        self::assertStringNotContainsString('999', $this->shown($crawler));
        self::assertTrue($this->logs()->hasInfoThatContains('lie refused a layout outside the page schema: /components/0/figure: Object contains unexpected additional properties: `value`.'));
    }

    public function testAnUnknownAttackIsNotFound(): void
    {
        $this->client->request('GET', '/lie?attack=../../.env');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string, string, string}> a component, what it tries to show,
     *                                                         what the page shows instead
     */
    public static function provideNumbersSmuggledOntoThePage(): iterable
    {
        $text = static fn (string $text): array => ['kind' => 'text', 'text' => $text];
        $period = static fn (string $from, ?string $to = null): array => ['kind' => 'period', 'from' => $from, 'to' => $to ?? $from];
        $figure = static fn (string $geo): array => ['kind' => 'figure', 'figure' => ['type' => 'measured', 'dataset' => 'nights_total', 'geo' => $geo, 'time' => '2024']];
        $note = static fn (array ...$segments): string => json_encode(['kind' => 'note', 'segments' => $segments], \JSON_THROW_ON_ERROR);
        $kpi = static fn (string $geo, string $time): string => json_encode(['kind' => 'kpi', 'label' => 'Nights', 'figure' => ['type' => 'measured', 'dataset' => 'nights_total', 'geo' => $geo, 'time' => $time]], \JSON_THROW_ON_ERROR);

        yield 'a value written as a period' => [$note($text('France counted '), $period('4576'), $text(' nights')), '4576', self::NO_PERIOD];
        yield 'a month the data does not have' => [$note($period('2024-12')), '2024-12', self::NO_PERIOD];
        yield 'a year before the data' => [$note($period('1999')), '1999', self::NO_PERIOD];
        yield 'a value written as a place' => [$kpi('457644262', '2024'), '457644262', 'Nights spent in tourist accommodation, (not a code), 2024'];
        yield 'a value written as a period of a cell' => [$kpi('FR', '4576'), '4576', 'Nights spent in tourist accommodation, France, (a period outside the data)'];
        yield 'digits from another script' => [$note($text('up ٤٥ percent')), '٤٥', self::NOTE_DIGIT];
        yield 'a numeral' => [$note($text('about ½ of all nights')), '½', self::NOTE_DIGIT];
        yield 'a numeral written with a letter' => [$note($text('up 三 percent')), '三', self::NOTE_DIGIT];
        yield 'a digit newer than the regex engine' => [$note($text("up \u{1CCF1}\u{1CCF2} percent")), "\u{1CCF1}", self::NOTE_DIGIT];
        yield 'a number in words' => [$note($text('up ninety-nine percent')), 'ninety', self::NOTE_DIGIT];
        yield 'a rank in words' => [$note($text('the second busiest country')), 'second', self::NOTE_DIGIT];
        yield 'a number in fullwidth letters' => [$note($text('up ｔｗｅｌｖｅ percent')), 'ｔｗｅｌｖｅ', self::NOTE_DIGIT];
        yield 'a number with a Cyrillic letter' => [$note($text("up tw\u{0435}lve times")), "tw\u{0435}lve", 'Refused: disguised letters are not allowed.'];
        yield 'a fullwidth percent sign' => [$note($text('up ％')), '％', self::NOTE_SIGN];
        yield 'a number split across text segments' => [$note($text('France counted '), $text('nin'), $text('ety'), $text(' per'), $text('c'), $text('ent more')), 'ety', self::NOTE_DIGIT];
        yield 'a number in words as a place' => [$kpi('NINETY', '2024'), 'NINETY', 'Nights spent in tourist accommodation, (not a code), 2024'];
        yield 'a minus sign before a figure' => [$note($text('France counted −'), $figure('FR'), $text(' nights')), '−', self::NOTE_SIGN];
        yield 'a percent sign after a figure' => [$note($text('France counted '), $figure('FR'), $text('% more nights')), '%', self::NOTE_SIGN];
        yield 'markup' => [$note($text('<b>more</b>')), '<b>', self::NOTE_SIGN];
        yield 'a right-to-left override around a figure' => [$note($text("France counted \u{202E}"), $figure('FR'), $text("\u{202C} nights")), "\u{202E}", self::NOTE_FORMAT];
        yield 'two periods glued together' => [$note($text('From '), $period('2019'), $period('2024')), '20192024', 'From 2019 2024'];
        yield 'two figures glued by a comma' => [$note($figure('FR'), $text(','), $figure('ES')), '457,644,262,505', '457,644,262 nights (France, 2024), 505,166,012 nights (Spain, 2024)'];
        yield 'a figure glued to a hyphen' => [$note($text('France counted -'), $figure('FR'), $text('nights')), '-457', 'France counted - 457,644,262 nights'];
    }

    #[DataProvider('provideNumbersSmuggledOntoThePage')]
    public function testANumberSmuggledThroughAnotherFieldIsNotShown(string $component, string $smuggled, string $shown): void
    {
        $crawler = $this->post('{"title": "Nights", "components": ['.$component.']}');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($shown, $crawler->filter('#result')->text());
        self::assertStringNotContainsString($smuggled, $this->shown($crawler));
    }

    public function testFiguresAndCodesKeepTheirOwnDirection(): void
    {
        $crawler = $this->client->request('GET', '/lie');

        self::assertStringContainsString('.gf-figure, .gf-code { unicode-bidi: isolate; }', $crawler->filter('#result style')->text());
    }

    public function testTextIsShownAsTextNotMarkup(): void
    {
        $crawler = $this->post('{"title": "Tom & \\"Jerry\\" say: \'hi\'", "components": []}');

        self::assertSelectorTextSame('#result .gf-title', 'Tom & "Jerry" say: \'hi\'');
        // Under the section "What the server shows": the page's title is a third-level heading.
        self::assertStringContainsString('<h3 class="gf-title">Tom &amp; &quot;Jerry&quot; say: &#039;hi&#039;</h3>', (string) $this->client->getResponse()->getContent());
    }

    public function testAPropertyNameIsNotEchoedWhenItHoldsANumber(): void
    {
        $crawler = $this->post('{"title": "Nights", "components": [], "Hotel nights in France: 999,000,000": 1, "comment": "hi"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['/: Object contains properties the page schema does not have.'], $crawler->filter('#result .errors li')->each(static fn (Crawler $c) => $c->text()));
        self::assertStringNotContainsString('999', $this->shown($crawler));

        $crawler = $this->post('{"title": "Nights", "components": [], "Hotel nights in France: nine hundred million": 1}');
        self::assertStringNotContainsString('nine hundred', $this->shown($crawler));
    }

    public function testAPropertyNameWithoutANumberIsEchoed(): void
    {
        $crawler = $this->post('{"title": "Nights", "components": [], "comment": "hi"}');

        self::assertSame(['/: Object contains unexpected additional properties: `comment`.'], $crawler->filter('#result .errors li')->each(static fn (Crawler $c) => $c->text()));
    }

    public function testAnEmptyObjectIsAnEmptyListAsForAnMcpCall(): void
    {
        // The SDK decodes tool arguments into arrays: {} is then an empty list, valid here.
        $this->post('{"title": "Nights", "components": {}}');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.tally', 'Figures proven: 0. Figures refused: 0. Texts or periods not shown: 0.');
    }

    public function testALayoutPostedFromAnotherSiteIsRefused(): void
    {
        $this->client->request('POST', '/lie', ['layout' => '{"title": "Nights", "components": []}'], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/lie', ['layout' => '{"title": "Nights", "components": []}'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseIsSuccessful();
    }

    public function testAPeriodWithinTheYearsOfTheDataIsShown(): void
    {
        $crawler = $this->post('{"title": "Nights", "components": [{"kind": "note", "segments": [{"kind": "period", "from": "2019", "to": "2024"}]}]}');

        self::assertSelectorTextSame('#result .gf-note', '2019 to 2024');
        self::assertSelectorTextSame('.tally', 'Figures proven: 0. Figures refused: 0. Texts or periods not shown: 0.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLayoutsThatAreNotPages(): iterable
    {
        yield 'not JSON' => ['{"title": "Nights",', 'This is not valid JSON: Syntax error.'];
        yield 'empty' => ['', 'This is not valid JSON: Syntax error.'];
        yield 'a bare value' => ['457644262', '/: Invalid type. Expected `object`, but received `integer`.'];
        yield 'no components' => ['{"title": "Nights"}', '/: Missing required properties: `components`.'];
        yield 'a component that is not in the schema' => ['{"title": "Nights", "components": [{"kind": "html", "html": "<b>99</b>"}]}', 'does not follow the page schema'];
        yield 'too long' => [str_repeat(' ', 64 * 1024 + 1).'{}', 'This layout is longer than any page needs (over 64 KB).'];
        yield 'too deep' => [str_repeat('[', 100).str_repeat(']', 100), 'This is not valid JSON: Maximum stack depth exceeded.'];
    }

    #[DataProvider('provideLayoutsThatAreNotPages')]
    public function testALayoutThatIsNotAPageIsRefusedWithItsReason(string $layout, string $reason): void
    {
        $crawler = $this->post($layout);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($reason, $crawler->filter('#result')->text());
        self::assertSelectorNotExists('.gf-page');
    }

    public function testTheErrorThatMattersComesFirst(): void
    {
        // A value written as a segment of its own: the checker also lists what the note misses to
        // be a tile or a chart, which would bury the error about the segment.
        $crawler = $this->post('{"title": "Nights", "components": [{"kind": "note", "segments": [{"kind": "value", "value": "99%"}]}]}');

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('/components/0/segments/0: ', $crawler->filter('#result .errors li')->first()->text());
    }

    public function testTheVisitorKeepsTheLayoutTheyWrote(): void
    {
        $layout = '{"title": "A <script>alert(\'x\')</script> & co", "components": []}';
        $crawler = $this->post($layout);

        self::assertSame($layout, $crawler->filter('textarea[name="layout"]')->text(normalizeWhitespace: false));
        self::assertStringNotContainsString('<script>', (string) $this->client->getResponse()->getContent());
    }

    public function testWithoutASnapshotThePageSaysSoAndTheErrorIsLogged(): void
    {
        unlink($this->facts);

        $this->client->request('GET', '/lie');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#result .problem', 'no data snapshot yet');
        self::assertSelectorNotExists('.gf-page');
        self::assertTrue($this->logs()->hasErrorThatContains('Try to make it lie cannot prove any figure: No data snapshot at'));
    }

    /**
     * The layouts a visitor starts from, one file per attack, each with a test copy. Every cell they
     * name is in the shipped catalog, except the ones the "cell" attack asks for on purpose; only the
     * "value" attack breaks the schema.
     */
    public function testTheShippedLayoutsNameCellsOfTheShippedCatalog(): void
    {
        $catalog = CatalogFixture::loader()->load(__DIR__.'/../../catalog');
        $fixtures = self::getContainer()->getParameter('app.lie_dir');
        $schema = new SchemaValidator();

        foreach (['start', ...array_keys(LieController::ATTACKS)] as $attack) {
            foreach ([$attack, $attack.'.fr'] as $name) {
                self::assertFileExists($fixtures.'/'.$name.'.json');
                $layout = json_decode((string) file_get_contents(self::SHIPPED.'/'.$name.'.json'), true, flags: \JSON_THROW_ON_ERROR);
                self::assertSame('value' === $attack, [] !== $schema->validateAgainstJsonSchema($layout, LayoutSchema::DEFINITION), $name);

                $cells = self::cells($layout);
                self::assertNotEmpty($cells, $name);

                $missing = array_filter($cells, static function (array $cell) use ($catalog): bool {
                    $entry = $catalog[$cell['dataset']] ?? null;

                    return null === $entry || !$entry->covers($cell['geo']) || !\in_array($cell['time'], $entry->time->periods(), true);
                });
                // The other cell the attack asks for, Ireland in 2017, is a place and a year of the entry: a
                // real gap of the data, which only the snapshot can tell.
                $expected = 'cell' === $attack ? [['dataset' => 'nights_hotels', 'geo' => 'UK', 'time' => '2024']] : [];
                self::assertSame($expected, array_values($missing), $name);
            }
        }
    }

    /**
     * @return list<array{dataset: string, geo: string, time: string}> every cell a layout names
     */
    private static function cells(mixed $node): array
    {
        if (!\is_array($node)) {
            return [];
        }
        $cells = \is_string($node['dataset'] ?? null) && \is_string($node['geo'] ?? null) && \is_string($node['time'] ?? null)
            ? [['dataset' => $node['dataset'], 'geo' => $node['geo'], 'time' => $node['time']]]
            : [];

        return array_merge($cells, ...array_values(array_map(self::cells(...), $node)));
    }

    private function post(string $layout): Crawler
    {
        return $this->client->request('POST', '/lie', ['layout' => $layout]);
    }

    /** What the server shows, without the layout the visitor typed, the styles, nor the proof tokens. */
    private function shown(Crawler $crawler): string
    {
        return (string) preg_replace(['#<style>.*?</style>#s', '/ (href|style)="[^"]*"/'], '', $crawler->filter('#result')->html());
    }

    private function logs(): TestHandler
    {
        return $this->client->getContainer()->get('monolog.handler.go_figure');
    }
}
