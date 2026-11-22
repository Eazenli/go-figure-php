<?php

declare(strict_types=1);

namespace App\Tests\Lie;

use App\Lie\Attacks;
use App\Tests\Support\FactsFixture;
use App\Ui\PageLink;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Attack this answer": the prepared attacks on the layout of an answer the visitor was reading,
 * named by the token of its /p link. The server checks each attacked layout like any other.
 */
final class AttackThisAnswerTest extends WebTestCase
{
    private const array ANSWER = ['title' => 'Nights in France and Spain', 'components' => [
        ['kind' => 'bar', 'title' => 'By country', 'bars' => [
            ['type' => 'measured', 'dataset' => 'nights_total', 'geo' => 'FR', 'time' => '2024'],
            ['type' => 'measured', 'dataset' => 'nights_total', 'geo' => 'ES', 'time' => '2024'],
        ]],
    ]];

    private KernelBrowser $client;
    private string $token;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        FactsFixture::write(self::getContainer()->getParameter('app.facts_path'));
        $this->token = PageLink::encode(self::ANSWER);
    }

    public function testTheAnswerOpensAsItWasWithTheAttacksItCanTake(): void
    {
        $crawler = $this->client->request('GET', '/lie?page='.$this->token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Try to make this answer lie');
        self::assertSelectorTextSame('#attack .tally', 'Figures proven: 2. Figures refused: 0. Texts or periods not shown: 0.');
        self::assertSame(array_values(Attacks::LABELS), $crawler->filter('.gf-attacks a[href*="attack="]')->each(static fn ($a) => $a->text()));
        // Every button keeps the answer: an attack is on this layout, not on the example's.
        self::assertCount(\count(Attacks::LABELS) + 1, $crawler->filter('.gf-attacks a[href*="page='.$this->token.'"]'));
        // The result first; the layout to edit by hand is a step further, folded.
        self::assertSelectorExists('details.gf-by-hand:not([open]) textarea[name="layout"]');
        self::assertSame($this->token, $crawler->filter('details.gf-by-hand input[name="page"]')->attr('value'));
    }

    public function testANumberSlippedIntoTheAnswerIsStruckOutAndBlanked(): void
    {
        $crawler = $this->client->request('GET', '/lie?page='.$this->token.'&attack=digits');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.hint', 'a number it wrote itself');
        self::assertSelectorTextSame('#result .gf-struck', 'That is up ██ on the year before.');
        self::assertSelectorTextSame('#result .gf-unsourced', 'Unsupported: the model wrote this number itself.');
        self::assertStringNotContainsString('12', $crawler->filter('#result .gf-note')->text());
        self::assertStringContainsString('"That is up 12% on the year before."', $crawler->filter('textarea[name="layout"]')->text());
        // The figures the model named are still read and proven.
        self::assertSelectorTextContains('#result .gf-verified', 'Proven');
        self::assertSelectorTextContains('#result .gf-verified', '2 figures read from Eurostat.');
    }

    public function testAValueWrittenByTheModelIsRefusedBeforeAnyFigureIsRead(): void
    {
        $this->client->request('GET', '/lie?page='.$this->token.'&attack=value');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#result .problem', 'Refused before any figure is read');
        self::assertSelectorNotExists('#result .gf-figure');
    }

    public function testAPlaceTheDataDoesNotHaveIsRefusedAndTheOtherBarStaysProven(): void
    {
        $crawler = $this->client->request('GET', '/lie?page='.$this->token.'&attack=cell');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#result .gf-refusal'));
        self::assertCount(1, $crawler->filter('#result .gf-bars a.gf-figure'));
        self::assertSelectorTextContains('#result .gf-verified', 'One figure read from Eurostat. One refused.');
    }

    public function testACalculationThatMeansNothingIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/lie?page='.$this->token.'&attack=compute');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#result .gf-kpi .gf-refusal'));
        self::assertCount(2, $crawler->filter('#result .gf-bars a.gf-figure'));
    }

    public function testAnInventedSourceIsNotShown(): void
    {
        $crawler = $this->client->request('GET', '/lie?page='.$this->token.'&attack=source');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#result .gf-unsourced', 'Refused: web addresses are not allowed.');
        // Not a number: nothing of the sentence is shown, not even struck out.
        self::assertSelectorNotExists('#result .gf-struck');
        self::assertStringNotContainsString('Ministry', $crawler->filter('#result')->text());
    }

    public function testAFlatteringCellIsProvenAndThePageSaysItDoesNotStopIt(): void
    {
        $crawler = $this->client->request('GET', '/lie?page='.$this->token.'&attack=swap');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.hint', 'the check does not stop this');
        self::assertCount(2, $crawler->filter('#result .gf-bars a.gf-figure'));
        self::assertSelectorExists('#limits');
    }

    public function testALayoutEditedByHandKeepsTheAnswerItCameFrom(): void
    {
        $crawler = $this->client->request('GET', '/lie?page='.$this->token);
        $form = $crawler->filter('details.gf-by-hand form')->form(['layout' => '{"title": "Mine", "components": []}']);
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('details.gf-by-hand[open]');
        self::assertCount(\count(Attacks::LABELS) + 1, $crawler->filter('.gf-attacks a[href*="page='.$this->token.'"]'));
    }

    public function testATokenThatHoldsNoPageIsNotFound(): void
    {
        $this->client->request('GET', '/lie?page=not-a-page');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAnswerWithoutAFigureOnlyTakesTheAttacksOnItsText(): void
    {
        $crawler = $this->client->request('GET', '/lie?page='.PageLink::encode(['title' => 'Nothing to show', 'components' => []]));

        self::assertResponseIsSuccessful();
        self::assertSame([Attacks::LABELS['digits'], Attacks::LABELS['source']], $crawler->filter('.gf-attacks a[href*="attack="]')->each(static fn ($a) => $a->text()));
    }

    /**
     * "Copy with source": the page as text for a report. Only what the server read, where it
     * comes from, and the link that proves it again.
     */
    public function testAnAnswerIsCopiedWithItsSources(): void
    {
        $crawler = $this->client->request('GET', '/p/'.$this->token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('details.gf-copy > summary', 'Copy with source');
        $text = $crawler->filter('details.gf-copy textarea')->text(normalizeWhitespace: false);
        self::assertStringStartsWith("Nights in France and Spain\n\nBy country\n- France, 2024: 457,644,262 nights\n- Spain, 2024: 505,166,012 nights\n\nSource: Eurostat, Nights spent in tourist accommodation (tour_occ_ninat), read on ", $text);
        self::assertStringContainsString('Every figure, with its proof: http://localhost/p/'.$this->token, $text);
        // The quiet line for the one who doubts, after what a reader uses.
        self::assertSelectorTextSame('.gf-actions a.gf-attack-open.gf-doubt', "Don't take our word for it: try to make it lie");
    }

    public function testACopiedAnswerLeavesOutWhatThePageDoesNotShow(): void
    {
        $crawler = $this->client->request('GET', '/p/'.PageLink::encode(['title' => 'Top 3 countries', 'components' => [
            ['kind' => 'bar', 'title' => 'By country', 'bars' => [
                ['type' => 'measured', 'dataset' => 'nights_total', 'geo' => 'FR', 'time' => '2024'],
                ['type' => 'measured', 'dataset' => 'nights_total', 'geo' => 'ZZ', 'time' => '2024'],
            ]],
            ['kind' => 'note', 'segments' => [['kind' => 'text', 'text' => 'That is up 12% on the year before.']]],
        ]]));

        $text = $crawler->filter('details.gf-copy textarea')->text(normalizeWhitespace: false);
        self::assertStringStartsWith("By country\n- France, 2024: 457,644,262 nights\n\nSource: Eurostat", $text);
        self::assertStringNotContainsString('12', (string) preg_replace('#http\S+#', '', $text));
        self::assertStringNotContainsString('ZZ', $text);
        self::assertStringNotContainsString('Top', $text);
    }

    public function testAFigureIsCopiedWithItsSourceFromItsProof(): void
    {
        $crawler = $this->client->request('GET', '/p/'.$this->token);
        $crawler = $this->client->request('GET', (string) $crawler->filter('.gf-bars a.gf-figure')->attr('href'));

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('.proof details.gf-copy textarea')->text(normalizeWhitespace: false);
        self::assertStringStartsWith("457,644,262 nights. Nights spent in tourist accommodation, France, 2024.\nSource: Eurostat, Nights spent in tourist accommodation (tour_occ_ninat), read on ", $text);
        self::assertStringContainsString("\nProof: http://localhost/proof/", $text);
    }

    public function testEveryAnswerOffersItsAttack(): void
    {
        $crawler = $this->client->request('GET', '/p/'.$this->token);
        self::assertSame('/lie?page='.$this->token, $crawler->filter('a.gf-attack-open')->attr('href'));

        $crawler = $this->client->request('GET', '/how');
        self::assertStringStartsWith('/lie?page=', (string) $crawler->filter('.example a.gf-attack-open')->attr('href'));
        $this->client->click($crawler->filter('.example a.gf-attack-open')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Try to make this answer lie');
    }
}
