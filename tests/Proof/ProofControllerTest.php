<?php

declare(strict_types=1);

namespace App\Tests\Proof;

use App\Proof\ProofToken;
use App\Tests\Support\FactsFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The stateless proof link, as a visitor opens it.
 */
final class ProofControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $path = self::getContainer()->getParameter('app.facts_path');
        FactsFixture::write($path);
    }

    public function testAGenuineProofIsShownWithItsFingerprint(): void
    {
        $proof = self::proof(['geo' => 'FR', 'time' => '2024', 'value' => 457644262]);
        $this->client->request('GET', '/proof/'.ProofToken::encode($proof));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.value', '457,644,262 nights');
        self::assertSelectorTextContains('.match', 'matches the snapshot');
        self::assertSelectorTextContains('.canonical', '"value":457644262');
        self::assertSelectorTextSame('.fingerprint', hash('sha256', (string) $this->client->getCrawler()->filter('.canonical')->text()));
        self::assertSelectorExists('a[href="https://ec.europa.eu/eurostat/databrowser/view/tour_occ_ninat/default/table"]');
        self::assertSelectorTextContains('dd', 'Nights spent in tourist accommodation');
        self::assertSelectorTextSame('.scope', 'Hotels, holiday homes and campsites; residents and non-residents.');
        self::assertSelectorTextContains('.licence', 'Eurostat');
        self::assertSelectorTextSame('.place', 'France FR: the whole country');
        // Eurostat publishes the value itself: nothing is computed by Go Figure.
        self::assertSelectorNotExists('.method');
        self::assertSelectorNotExists('.attribution');
        // The pitfalls are notes for the model, with hand-written numbers: never on a proof page.
        self::assertSelectorNotExists('.pitfalls');
        self::assertAnySelectorTextNotContains('main', 'Not hotels only');
    }

    public function testAFigureComputedForACitySaysHowWhereAndWhoToCredit(): void
    {
        $url = 'https://archive-api.open-meteo.com/v1/archive?latitude=37.393&longitude=-5.956&start_date=1991-01-01&end_date=2020-12-31&daily=temperature_2m_max&models=cerra&timezone=GMT';
        $proof = self::proof(['provider' => 'open_meteo', 'dataset' => 'temp_max_city', 'geo' => 'ES004C', 'time' => 'M09', 'unit' => 'degC', 'value' => 31.1, 'url' => $url]);
        $this->client->request('GET', '/proof/'.ProofToken::encode($proof));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.value', '31.1 °C');
        self::assertSelectorTextSame('.place', 'Seville ES004C: a city in Spain');
        self::assertSelectorTextContains('.method', 'Go Figure averages the daily maximum temperature of every day of that month');
        self::assertAnySelectorTextContains('dt', 'Downloaded from the source');
        // The source link returns the daily values the figure is computed from.
        self::assertSelectorExists('a[href="'.$url.'"]');
        self::assertSelectorExists('.attribution a[href="https://open-meteo.com/"]');
        self::assertSelectorTextContains('.attribution', 'Contains modified Copernicus Climate Change Service information.');
    }

    public function testAnEditedValueIsNotShown(): void
    {
        $this->client->request('GET', '/proof/'.ProofToken::encode(self::proof(['geo' => 'FR', 'time' => '2024', 'value' => 500000000])));

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('.problem', 'does not match');
        self::assertSelectorNotExists('.canonical');
    }

    public function testAnEditedSourceLinkIsNotShown(): void
    {
        $this->client->request('GET', '/proof/'.ProofToken::encode(self::proof(['geo' => 'FR', 'time' => '2024', 'value' => 457644262, 'url' => 'https://example.org/'])));

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('.problem', 'does not match');
    }

    public function testACellWithoutDataGivesItsReason(): void
    {
        $this->client->request('GET', '/proof/'.ProofToken::encode(self::proof(['geo' => 'DE', 'time' => '2024', 'value' => 1])));

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('.problem', 'no data for this place');
    }

    public function testAMalformedLinkIsRejected(): void
    {
        $this->client->request('GET', '/proof/not-a-proof');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('.problem', 'malformed');
    }

    /**
     * @param array<string, mixed> $cell
     *
     * @return array<string, mixed>
     */
    private static function proof(array $cell): array
    {
        return $cell + [
            'v' => 1,
            'type' => 'measured',
            'provider' => 'eurostat',
            'dataset' => 'nights_total',
            'unit' => 'NR',
            'status' => '',
            'source_updated' => FactsFixture::SOURCE_UPDATED,
            'snapshot_date' => FactsFixture::SNAPSHOT_DATE,
            'url' => 'https://ec.europa.eu/eurostat/databrowser/view/tour_occ_ninat/default/table',
        ];
    }
}
