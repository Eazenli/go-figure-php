<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Proof\ProofToken;
use App\Tests\Support\FactsFixture;
use App\Tests\Support\McpRequests;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * The interface in English by default, in French if the visitor chooses it. What MCP sends stays
 * in English, and the rule of the page refuses numbers written in either language.
 */
final class LanguageTest extends WebTestCase
{
    use McpRequests;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        FactsFixture::write(self::getContainer()->getParameter('app.facts_path'));
    }

    public function testTheSwitchRemembersTheLanguageAndGoesBackToThePage(): void
    {
        $this->client->request('GET', '/language/fr?back=/compare');

        self::assertResponseRedirects('/compare');
        $cookie = $this->client->getCookieJar()->get('lang');
        self::assertNotNull($cookie);
        self::assertSame('fr', $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());

        $crawler = $this->client->followRedirect();
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSame('Comparer des destinations', $crawler->filter('h1')->text());
        self::assertSame('FR', $crawler->filter('header.site .language a[aria-current]')->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function elsewhere(): iterable
    {
        yield 'another site' => ['https://evil.example/'];
        yield 'another site, without a scheme' => ['//evil.example/'];
        yield 'a backslash' => ['/\evil.example'];
        yield 'not a path' => ['javascript:alert(1)'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('elsewhere')]
    public function testTheSwitchNeverLeadsElsewhere(string $back): void
    {
        $this->client->request('GET', '/language/fr?'.http_build_query(['back' => $back]));

        self::assertResponseRedirects('/');
    }

    public function testAnUnknownLanguageIsNotFound(): void
    {
        $this->client->request('GET', '/language/de');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAFrenchPageWritesItsNumbersCountriesMonthsAndUnitsTheFrenchWay(): void
    {
        $this->client->getCookieJar()->set(new Cookie('lang', 'fr'));
        $crawler = $this->client->request('GET', '/compare?'.http_build_query(['places' => ['ES', 'FR'], 'month' => '09']));

        self::assertResponseIsSuccessful();
        self::assertSame('Destinations côte à côte', $crawler->filter('hr + h2')->text());
        // French typography: a narrow no-break space before "?", a no-break space before ":".
        self::assertSame("Y aura-t-il du monde\u{202F}?", $crawler->filter('.gf-page .gf-title')->first()->text());
        self::assertSame('Nights spent in tourist accommodation, année entière 2024', $crawler->filter('.gf-page .gf-heading')->first()->text());
        // Narrow no-break spaces between thousands, the country names in French, the unit too.
        // Sorted by their French names: Espagne, then France.
        self::assertSame(["505\u{202F}166\u{202F}012", "457\u{202F}644\u{202F}262"], $crawler->filter('.gf-page .gf-bars a.gf-figure')->each(static fn ($a) => $a->text()));
        self::assertSame(['Espagne', 'France'], $crawler->filter('.gf-page .gf-bars')->first()->filter('.gf-cell')->each(static fn ($c) => $c->text()));
        self::assertSame('nuitées', $crawler->filter('.gf-page .gf-bars .gf-unit')->first()->text());
        self::assertSame('sept.', $crawler->filter('input[name="month"][value="09"]')->ancestors()->first()->text());
        self::assertSame('septembre', $crawler->filter('input[name="month"][value="09"]')->attr('aria-label'));
        self::assertStringContainsString('Absent. ', $crawler->filter('.gf-themes')->text());
    }

    public function testAFrenchProofSaysTheSameInFrench(): void
    {
        $this->client->getCookieJar()->set(new Cookie('lang', 'fr'));
        $proof = ['v' => 1, 'type' => 'measured', 'provider' => 'eurostat', 'dataset' => 'nights_total', 'geo' => 'FR', 'time' => '2024', 'unit' => 'NR', 'value' => 457644262, 'status' => '', 'source_updated' => FactsFixture::SOURCE_UPDATED, 'snapshot_date' => FactsFixture::SNAPSHOT_DATE, 'url' => 'https://ec.europa.eu/eurostat/databrowser/view/tour_occ_ninat/default/table'];
        $this->client->request('GET', '/proof/'.ProofToken::encode($proof));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Preuve');
        self::assertSelectorTextSame('.value', "457\u{202F}644\u{202F}262 nuitées");
        self::assertSelectorTextSame('.place', "France FR\u{00A0}: tout le pays");
        // The canonical form and its fingerprint do not depend on the language.
        self::assertSelectorTextContains('.canonical', '"value":457644262');
    }

    public function testEachLanguageShowsTheExampleQuestionsAskedInIt(): void
    {
        file_put_contents(self::getContainer()->getParameter('app.questions_file'), <<<'YAML'
            - { id: nights, text: "Nights in France?", provider: huggingface, model: "openai/gpt-oss-120b:cerebras", cassette: var/test/cassettes/nights.json, recorded: scripted }
            - { id: nights-fr, language: fr, twin: nights, text: "Les nuitées en France ?", provider: huggingface, model: "openai/gpt-oss-120b:cerebras", cassette: var/test/cassettes/nights-fr.json, recorded: scripted }
            YAML);

        $crawler = $this->client->request('GET', '/');
        self::assertSame(['Nights in France?'], $crawler->filter('.gf-examples a')->each(static fn ($a) => $a->text()));

        $this->client->getCookieJar()->set(new Cookie('lang', 'fr'));
        $crawler = $this->client->request('GET', '/');
        self::assertSame(['Les nuitées en France ?'], $crawler->filter('.gf-examples a')->each(static fn ($a) => $a->text()));
        self::assertSame('fr', $crawler->filter('.gf-examples a')->attr('lang'));

        // Switching the language on an example question leads to the same question in that language.
        $this->client->request('GET', '/?q=nights');
        self::assertResponseRedirects('/?q=nights-fr#result');
        $this->client->getCookieJar()->set(new Cookie('lang', 'en'));
        $this->client->request('GET', '/?q=nights-fr');
        self::assertResponseRedirects('/?q=nights#result');
    }

    public function testTheHomePageShowsItsExampleAsAModelAskedInFrenchWouldWriteIt(): void
    {
        $this->client->getCookieJar()->set(new Cookie('lang', 'fr'));
        $crawler = $this->client->request('GET', '/how');

        self::assertSame('Nuitées : France, Espagne, Italie et Royaume-Uni', $crawler->filter('.example .gf-title')->text());
        // "en hausse de douze pour cent": a number written in French words, not shown.
        self::assertSelectorTextContains('.example .gf-note .gf-unsourced', "Sans preuve\u{A0}: le modèle a écrit ce nombre lui-même.");
        self::assertAnySelectorTextNotContains('.example', 'douze');
        self::assertSame("457\u{202F}644\u{202F}262", $crawler->filter('.example a.gf-figure')->first()->text());
    }

    public function testTryToMakeItLieStartsFromAFrenchLayout(): void
    {
        $this->client->getCookieJar()->set(new Cookie('lang', 'fr'));
        $crawler = $this->client->request('GET', '/lie?attack=digits');

        self::assertStringContainsString('en hausse de 12 %', $crawler->filter('#layout')->text());
        self::assertSelectorTextContains('#result .gf-note .gf-unsourced', 'Sans preuve');
        self::assertSelectorTextContains('.hint', 'trois pays');
    }

    public function testTryToMakeItLieExplainsItsRefusalsInFrench(): void
    {
        $this->client->getCookieJar()->set(new Cookie('lang', 'fr'));
        $crawler = $this->client->request('GET', '/lie?attack=value');
        self::assertSame("/components/0/figure: Propriétés que le schéma de page n'a pas\u{A0}: value.", $crawler->filter('#result .errors li')->first()->text());
        // The logs keep the English.
        self::assertTrue($this->client->getContainer()->get('monolog.handler.go_figure')->hasInfoThatContains('lie refused a layout outside the page schema: /components/0/figure: Object contains unexpected additional properties: `value`.'));

        $this->client->request('POST', '/lie', ['layout' => '{"title": "Nuitées",']);
        self::assertSelectorTextContains('#result .problem', "Ce n'est pas du JSON valide\u{A0}: une erreur de syntaxe.");
    }

    public function testACityIsNamedAndExplainedInFrench(): void
    {
        $this->client->getCookieJar()->set(new Cookie('lang', 'fr'));
        $url = 'https://archive-api.open-meteo.com/v1/archive?latitude=37.393&longitude=-5.956&start_date=1991-01-01&end_date=2020-12-31&daily=temperature_2m_max&models=cerra&timezone=GMT';
        $proof = ['v' => 1, 'type' => 'measured', 'provider' => 'open_meteo', 'dataset' => 'temp_max_city', 'geo' => 'ES004C', 'time' => 'M09', 'unit' => 'degC', 'value' => 31.1, 'status' => '', 'source_updated' => FactsFixture::SOURCE_UPDATED, 'snapshot_date' => FactsFixture::SNAPSHOT_DATE, 'url' => $url];
        $this->client->request('GET', '/proof/'.ProofToken::encode($proof));

        self::assertSelectorTextSame('.value', '31,1 °C');
        self::assertSelectorTextSame('.place', "Séville ES004C\u{00A0}: une ville, Espagne");
        self::assertSelectorTextContains('.method', 'Go Figure fait la moyenne');
        self::assertSelectorNotExists('.method[lang]');
    }

    public function testMcpStaysInEnglishWhateverTheSiteLanguage(): void
    {
        $this->client->getCookieJar()->set(new Cookie('lang', 'fr'));
        $session = $this->initialize($this->client);
        $result = $this->rpc($this->client, $session, 'tools/call', ['name' => 'render_page', 'arguments' => ['layout' => [
            'title' => 'Nights', 'components' => [['kind' => 'kpi', 'label' => 'France', 'figure' => ['type' => 'measured', 'dataset' => 'nights_total', 'geo' => 'FR', 'time' => '2024']]],
        ]]])['result'];

        self::assertStringContainsString('[457,644,262]', $result['content'][0]['text']);
    }

    public function testNumbersWrittenInFrenchAreRefusedOnEveryPage(): void
    {
        $this->client->request('POST', '/lie', ['layout' => json_encode(['title' => 'Hausse de douze pour cent', 'components' => [['kind' => 'note', 'segments' => [['kind' => 'text', 'text' => 'Une dizaine de villes, et la première.']]]]], \JSON_THROW_ON_ERROR)]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#result .gf-title', 'Unsupported: the model wrote this number itself.');
        self::assertSelectorTextContains('#result .gf-note', 'Unsupported: the model wrote this number itself.');
    }
}
