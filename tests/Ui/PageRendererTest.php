<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Catalog\Catalog;
use App\Layout\Constraint\FreeText;
use App\Layout\Page;
use App\Proof\ProofToken;
use App\Proof\Resolver;
use App\Tests\Support\FactsFixture;
use App\Ui\PageRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Twig\Environment;

/**
 * A layout as a model would write it, rendered to HTML: what the visitor sees.
 */
final class PageRendererTest extends KernelTestCase
{
    private const string LAYOUT = <<<'JSON'
        {
            "title": "Nights in France and Spain",
            "components": [
                {"kind": "kpi", "label": "Nights in France", "figure": {"type": "measured", "dataset": "nights_total", "geo": "FR", "time": "2024"}},
                {"kind": "bar", "title": "Nights by country", "bars": [
                    {"type": "measured", "dataset": "nights_total", "geo": "FR", "time": "2024"},
                    {"type": "measured", "dataset": "nights_total", "geo": "ES", "time": "2024"},
                    {"type": "measured", "dataset": "nights_total", "geo": "DE", "time": "2024"},
                    {"type": "computed", "op": "change_pct", "operands": [
                        {"type": "measured", "dataset": "nights_total", "geo": "FR", "time": "2019"},
                        {"type": "measured", "dataset": "nights_total", "geo": "FR", "time": "2024"}
                    ]}
                ]},
                {"kind": "line", "title": "Nights over time", "series": [
                    {"dataset": "nights_total", "geo": "FR", "from": "2019", "to": "2024"},
                    {"dataset": "nights_hotels", "geo": "FR", "from": "2019", "to": "2024"}
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
                    {"kind": "text", "text": ", up 99% since "},
                    {"kind": "period", "from": "1999 or so", "to": "2019"}
                ]}
            ]
        }
        JSON;

    private Crawler $html;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $path = $container->getParameter('app.facts_path');
        FactsFixture::write($path);

        $page = $container->get(SerializerInterface::class)->deserialize(self::LAYOUT, Page::class, 'json');
        $renderer = new PageRenderer($container->get(Resolver::class), $container->get(ValidatorInterface::class), $container->get(Environment::class), $container->get(Catalog::class));
        $this->html = new Crawler($renderer->render($page));
    }

    public function testEveryValueShowsItsUnitOnce(): void
    {
        self::assertSame('nights', $this->html->filter('.gf-kpi .gf-unit')->text());
        self::assertCount(2, $this->html->filter('.gf-bars .gf-unit')); // FR and ES; DE and the calculation are refused
        // The model wrote the unit after the figure: the server's stays, the repetition goes, and the
        // server says which cell the figure is.
        self::assertStringStartsWith('France counted 457,644,262 nights (France, 2024) in 2024', $this->html->filter('.gf-note')->text());
        self::assertStringContainsString('France, 2024: 457,644,262 nights', $this->html->filter('.gf-line svg')->text());
    }

    public function testAMissingYearBreaksTheLineAndKeepsItsPlace(): void
    {
        // France 2019-2024 has 2019, 2020, 2023 and 2024: two runs, and 2021-2022 stay empty.
        $circles = $this->html->filter('.gf-line circle')->each(static fn ($c) => (float) $c->attr('cx'));
        self::assertSame([10.0, 126.0, 474.0, 590.0], \array_slice($circles, 0, 4));
        self::assertGreaterThanOrEqual(2, \count($this->html->filter('.gf-line polyline')));
        // One colour per series drawn, in a fixed order; the refused series has none.
        self::assertSame(['gf-swatch gf-s1'], $this->html->filter('.gf-legend .gf-swatch')->each(static fn ($s) => $s->attr('class')));
    }

    public function testTheScaleOfALineIsToldByProvenValuesOnly(): void
    {
        // The highest and the lowest point label the two hairlines, each a link to its proof; the
        // periods run along the bottom. No other number is drawn.
        $scale = $this->html->filter('.gf-line .gf-scale')->each(static fn ($t) => $t->text());
        self::assertCount(2, $scale);
        self::assertCount(2, $this->html->filter('.gf-line a[href^="http://localhost/proof/"] .gf-scale'));
        self::assertCount(2, $this->html->filter('.gf-line .gf-grid'));
        self::assertSame(['2019', '2020', '2021', '2022', '2023', '2024'], $this->html->filter('.gf-line .gf-tick')->each(static fn ($t) => $t->text()));
        self::assertSame(['start', 'middle', 'middle', 'middle', 'middle', 'end'], $this->html->filter('.gf-line .gf-tick')->each(static fn ($t) => $t->attr('text-anchor')));
    }

    public function testEveryValueIsAProofLink(): void
    {
        $kpi = $this->html->filter('.gf-kpi .gf-figure');
        self::assertSame('457,644,262', $kpi->text());

        $token = substr((string) $kpi->attr('href'), \strlen('http://localhost/proof/'));
        self::assertSame(457644262, ProofToken::decode($token)['value'] ?? null);
    }

    public function testBarsAreScaledOnTheirProvenValues(): void
    {
        $bars = $this->html->filter('.gf-bar')->each(static fn (Crawler $bar) => $bar->attr('style'));

        self::assertSame(['width: 90.6%', 'width: 100%'], $bars);
    }

    public function testFiguresThatCannotBeProvenBecomeRefusals(): void
    {
        $refusals = $this->html->filter('.gf-refusal')->each(static fn (Crawler $r) => $r->text());

        self::assertSame([
            'Missing: This source has no data for this place.',
            // A change in percent among bars in nights: computed, but not on the scale of this chart.
            'Refused: Another unit or another kind of period than the first figure of this chart: not on the same scale.',
            'Refused: This dataset is not in the catalog.',
            'Missing: The source publishes no value for this place and period.',
        ], $refusals);
    }

    public function testALineHasOnePointPerProvenPeriod(): void
    {
        self::assertCount(4, $this->html->filter('.gf-line circle'));
        self::assertCount(4, $this->html->filter('.gf-line svg a[href^="http://localhost/proof/"] circle'));
    }

    public function testASourceFlagIsShownNextToItsValue(): void
    {
        self::assertSame('e', $this->html->filter('.gf-table .gf-flag')->text());
        self::assertSame('estimated', $this->html->filter('.gf-table .gf-flag')->attr('title'));
    }

    public function testDigitsWrittenByTheModelNeverReachThePage(): void
    {
        // The styles hold colours such as #199e70: what matters is everything else.
        self::assertStringNotContainsString('99', (string) preg_replace('#<style>.*?</style>#s', '', $this->html->html()));
        self::assertSame(
            ['Unsupported: the model wrote this number itself.', 'This period is not a year, a month or a month of the year.'],
            $this->html->filter('.gf-note .gf-unsourced')->each(static fn (Crawler $u) => $u->text()),
        );
    }

    private function render(string $layout): Crawler
    {
        $container = self::getContainer();
        $renderer = new PageRenderer($container->get(Resolver::class), $container->get(ValidatorInterface::class), $container->get(Environment::class), $container->get(Catalog::class));

        return new Crawler($renderer->render($container->get(SerializerInterface::class)->deserialize($layout, Page::class, 'json')));
    }

    /**
     * Criterion 3 of the go/no-go: every digit a visitor can read was written by the server, from a
     * proof or a checked code.
     */
    public function testEveryVisibleDigitComesFromTheServer(): void
    {
        $document = $this->html->getNode(0)?->ownerDocument;
        self::assertInstanceOf(\DOMDocument::class, $document);
        $xpath = new \DOMXPath($document);
        // In a chart: the tooltip of a point (written from its proof), a period of the axis and a
        // label of the scale, each checked below; nothing else.
        $allowed = 'ancestor::style or ancestor::*[local-name()="title" and ancestor::*[local-name()="svg"]]'
            .' or ancestor::*[contains(concat(" ", @class, " "), " gf-tick ")]'
            .' or ancestor::*[contains(concat(" ", @class, " "), " gf-scale ")]'
            // The halo drawn under a scale label: the same proven figure, a second time.
            .' or ancestor::*[contains(concat(" ", @class, " "), " gf-halo ")]'
            .' or ancestor::*[contains(concat(" ", @class, " "), " gf-figure ")]'
            .' or ancestor::*[contains(concat(" ", @class, " "), " gf-code ")]'
            // The stamp of a block: how many figures the server read for it, counted by the server.
            .' or ancestor::*[contains(concat(" ", @class, " "), " gf-verified-what ")]'
            // What a block counts: a sentence of the catalog, which may hold the years of a normal.
            .' or ancestor::*[contains(concat(" ", @class, " "), " gf-scope ")]';

        $stray = [];
        foreach ($xpath->query('//text()[not('.$allowed.')]') ?: [] as $node) {
            if (1 === preg_match('/\p{N}/u', (string) $node->nodeValue)) {
                $stray[] = trim((string) $node->nodeValue);
            }
        }

        self::assertSame([], $stray);
    }

    /**
     * The axis shows only periods the dataset covers: a series asked "to 4576" puts no "4576" on
     * the page, and the scale shows only values of points of the chart, each linked to its proof.
     */
    public function testAModelCannotWriteANumberThroughTheAxis(): void
    {
        $html = $this->render('{"title": "Nights", "components": [{"kind": "line", "title": "Over time", "series": [{"dataset": "nights_total", "geo": "FR", "from": "2019", "to": "4576"}]}]}');
        $catalog = self::getContainer()->get(Catalog::class);

        $ticks = $html->filter('.gf-tick')->each(static fn (Crawler $t) => $t->text());
        self::assertNotEmpty($ticks);
        foreach ($ticks as $tick) {
            self::assertTrue($catalog->knowsPeriod($tick), $tick);
        }
        self::assertStringNotContainsString('4576', (string) preg_replace('#<style>.*?</style>#s', '', $html->html()));

        $points = $html->filter('.gf-line a circle')->each(static fn (Crawler $c) => [$c->ancestors()->first()->attr('href'), $c->filter('title')->text()]);
        foreach ($html->filter('.gf-scale') as $label) {
            $link = $label->parentNode;
            self::assertInstanceOf(\DOMElement::class, $link);
            $point = array_values(array_filter($points, static fn (array $p) => $p[0] === $link->getAttribute('href')));
            self::assertCount(1, $point, 'A scale label links to the proof of a point of the chart.');
            self::assertStringEndsWith(': '.$label->textContent, $point[0][1]);
        }
    }

    public function testFiguresOfAnotherUnitAreRefusedRatherThanDrawnOnTheSameScale(): void
    {
        $html = $this->render('{"title": "Mixed", "components": ['
            .'{"kind": "bar", "title": "Bars", "bars": [{"type": "measured", "dataset": "nights_total", "geo": "FR", "time": "2024"}, {"type": "measured", "dataset": "temp_max_city", "geo": "ES004C", "time": "M09"}]},'
            .'{"kind": "line", "title": "Lines", "series": [{"dataset": "nights_total", "geo": "FR", "from": "2019", "to": "2024"}, {"dataset": "temp_max_city", "geo": "ES004C", "from": "M01", "to": "M12"}]}'
            .']}');

        $refusal = 'Refused: Another unit or another kind of period than the first figure of this chart: not on the same scale.';
        self::assertSame([$refusal], $html->filter('.gf-bars .gf-refusal')->each(static fn (Crawler $r) => $r->text()));
        self::assertSame([$refusal], $html->filter('.gf-legend .gf-refusal')->each(static fn (Crawler $r) => $r->text()));
        self::assertCount(1, $html->filter('.gf-legend .gf-swatch'), 'A refused series has no colour.');
    }

    public function testABarNeverStandsForANegativeValue(): void
    {
        $html = $this->render('{"title": "January", "components": [{"kind": "bar", "title": "Afternoons", "bars": [{"type": "measured", "dataset": "temp_max_city", "geo": "ES004C", "time": "M01"}, {"type": "measured", "dataset": "temp_max_city", "geo": "PT001C", "time": "M01"}]}]}');

        // Below zero: the value, no bar at all (not even a sliver).
        self::assertCount(2, $html->filter('.gf-track'));
        self::assertSame(['width: 100%'], $html->filter('.gf-bar')->each(static fn (Crawler $b) => $b->attr('style')));
        self::assertSame('-1.5 °C', $html->filter('.gf-bar-value .gf-figure')->first()->text());
    }

    public function testAPageTitleWithADigitIsMarkedUnsourced(): void
    {
        $container = self::getContainer();
        $page = $container->get(SerializerInterface::class)->deserialize('{"title": "Top 3 countries", "components": []}', Page::class, 'json');
        $renderer = new PageRenderer($container->get(Resolver::class), $container->get(ValidatorInterface::class), $container->get(Environment::class), $container->get(Catalog::class));

        $html = new Crawler($renderer->render($page));

        // What the model tried, struck out with its number blanked, then why it is not shown.
        self::assertSame('Top ██ countries', $html->filter('h1 .gf-struck')->text());
        self::assertSame('Unsupported: the model wrote this number itself.', $html->filter('h1 .gf-unsourced')->text());
        self::assertStringNotContainsString('3', $html->filter('h1')->text());
    }

    /**
     * A number in words cannot be blanked by its digits: the text is not shown at all. Nor is a
     * text whose problem is not a number.
     */
    public function testARefusedTextIsStruckOutOnlyWhenBlankingItsDigitsIsEnough(): void
    {
        self::assertSame(', up ██ on the year before.', FreeText::blanked(', up 12% on the year before.'));
        self::assertSame('From ██ to ██ nights', FreeText::blanked('From 1,200 to 3 400 nights'));
        self::assertSame('A balmy ██ in January', FreeText::blanked('A balmy 24 °C in January'));
        self::assertSame('It cost ██ more', FreeText::blanked('It cost +€5.50 more'));
        self::assertNull(FreeText::blanked('Twelve countries and 3 more'));
        self::assertNull(FreeText::blanked('See www.example.org, 3 times'));
        self::assertNull(FreeText::blanked('No number here'));
        self::assertNull(FreeText::blanked("tw\u{0435}lve and 3"));
        // A number written into a word, an ordinal, a magnitude: the letters around the blank
        // would still say it.
        foreach (['Up f0ur points', 'tw3lve nights', 'the 3rd year', 'about 5k nights', 'm1ll1on'] as $text) {
            self::assertNull(FreeText::blanked($text), $text);
        }
        self::assertSame('It fell by ██ in a year', FreeText::blanked('It fell by -5 in a year'));
        // Cut between words.
        self::assertStringEndsWith('word…', (string) FreeText::blanked('Up 3 '.str_repeat('word ', 60)));
    }

    /**
     * A page that streams in (/ask) gets the credits of its sources with its last event: they
     * depend on every figure of the page.
     */
    public function testTheCreditsOfAPageComeFromTheSourcesOfItsProvenFigures(): void
    {
        $container = self::getContainer();
        $renderer = new PageRenderer($container->get(Resolver::class), $container->get(ValidatorInterface::class), $container->get(Environment::class), $container->get(Catalog::class));
        $page = static fn (string $geo): Page => $container->get(SerializerInterface::class)->deserialize(
            '{"title": "Afternoons", "components": [{"kind": "kpi", "label": "In September", "figure": {"type": "measured", "dataset": "temp_max_city", "geo": "'.$geo.'", "time": "M09"}}]}',
            Page::class,
            'json',
        );

        $credits = new Crawler($renderer->renderCredits($renderer->view($page('ES004C'))));
        self::assertSame('Sources: Weather data by Open-Meteo.com; Contains modified Copernicus Climate Change Service information.', $credits->filter('.gf-credits')->text());
        self::assertSame('https://open-meteo.com/', $credits->filter('.gf-credits a')->attr('href'));
        // A refused figure shows nothing of the source: no credits.
        self::assertSame('', trim($renderer->renderCredits($renderer->view($page('FR')))));
        // A source that asks for none (Eurostat) adds nothing.
        self::assertCount(0, $this->html->filter('.gf-credits'));
    }
}
