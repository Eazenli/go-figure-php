<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Catalog\Catalog;
use App\Import\CityWeather;
use App\Import\Normals;
use App\Tests\Support\FactsFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * "Add a city" (the extended mode): one request to Open-Meteo, the normals computed here as
 * data/weather.py computes them (the same cases as its tests), and a city that is then in the
 * catalog, on /compare and on a proof. Open-Meteo is played by a response built here: no network.
 */
final class CityWeatherTest extends WebTestCase
{
    private KernelBrowser $client;
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $asked;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        FactsFixture::write($container->getParameter('app.facts_path'));
        $container->get('cache.rate_limiter')->clear();
        $imported = $container->getParameter('app.imported_path');
        if (is_file($imported)) {
            unlink($imported);
        }
        $this->asked = new \ArrayObject();
    }

    public function testTheNormalsAreComputedAsTheShippedOnes(): void
    {
        $response = self::response();
        Normals::check($response);

        $temperature = Normals::of($response, 'temperature_2m_max');
        self::assertSame(11.0, $temperature['M01']);
        self::assertSame(22.0, $temperature['M12']);
        $rain = Normals::of($response, 'precipitation_sum');
        self::assertSame(31.0, $rain['M01']);
        // 22 Februaries of 28 days and 8 of 29: 848 / 30.
        self::assertSame(28.3, $rain['M02']);
        // Half up, as a spreadsheet: the mean of 2.2 and 2.3 is 2.3, of -2.2 and -2.3 is -2.3.
        $pair = static fn (float $a, float $b): array => Normals::of(self::response(static fn (\DateTimeImmutable $d): float => '01' === $d->format('d') ? $a : $b, days: 2), 'temperature_2m_max');
        self::assertSame(['M01' => 2.3], $pair(2.2, 2.3));
        self::assertSame(['M01' => -2.3], $pair(-2.2, -2.3));
    }

    public function testAMonthTooIncompleteHasNoNormal(): void
    {
        $gaps = self::response(static fn (\DateTimeImmutable $d): ?float => '09' === $d->format('m') && $d->format('Y') < '1995' ? null : 30.0);
        self::assertArrayNotHasKey('M09', Normals::of($gaps, 'temperature_2m_max'));

        $rain = self::response(rain: static fn (\DateTimeImmutable $d): ?float => '03-10' === $d->format('m-d') && \in_array($d->format('Y'), ['1995', '1996', '1997', '1998'], true) ? null : 2.0);
        self::assertArrayNotHasKey('M03', Normals::of($rain, 'precipitation_sum'));
        self::assertSame(56.5, Normals::of($rain, 'precipitation_sum')['M02']);
    }

    public function testAnUnexpectedAnswerIsRefused(): void
    {
        $response = self::response();
        $response['daily_units']['temperature_2m_max'] = '°F';

        $this->expectException(\UnexpectedValueException::class);
        Normals::check($response);
    }

    public function testACityIsAddedThenComparedWithItsProof(): void
    {
        $this->openMeteo(new MockResponse((string) json_encode(self::response())));
        $crawler = $this->client->request('GET', '/import');
        // Seville is in the test catalog already; a point noted as misplaced is not offered.
        $offered = $crawler->filter('select[name="city"] option')->each(static fn ($o) => (string) $o->attr('value'));
        self::assertContains('ES006C', $offered);
        self::assertNotContains('ES004C', $offered);
        self::assertNotContains('NO001C', $offered);

        $this->client->request('POST', '/import/city', ['city' => 'ES006C'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects('/import?city=ES006C');
        self::assertCount(1, $this->asked);
        self::assertStringStartsWith('https://archive-api.open-meteo.com/v1/archive?latitude=36.768&longitude=-4.424&start_date=1991-01-01&end_date=2020-12-31&daily=temperature_2m_max', (string) $this->asked[0]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.added h2', 'Added: Malaga');
        // The link compares it with a shipped city: /compare needs two places.
        $this->client->request('GET', (string) $this->client->getCrawler()->filter('.added a.button')->attr('href'));
        self::assertResponseIsSuccessful();
        // A code typed into the address is not "added".
        $this->client->request('GET', '/import?city=ES004C');
        self::assertSelectorNotExists('.added');

        $crawler = $this->client->request('GET', '/compare?'.http_build_query(['places' => ['ES006C', 'ES004C'], 'month' => '09']));
        self::assertResponseIsSuccessful();
        $malaga = $crawler->filter('.gf-page .gf-bars li')->reduce(static fn ($li): bool => str_contains($li->text(), 'Malaga'))->last();
        $figure = $malaga->filter('a.gf-figure');
        self::assertStringStartsWith('19', $figure->text());

        // The proof links to the request of that city: the daily values the normal is computed from.
        $this->client->request('GET', (string) $figure->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.imported', 'The weather of this city was added on this machine');
        self::assertSelectorExists('a[href*="latitude=36.768&longitude=-4.424"]');
        // The example questions keep the catalog they were recorded with.
        self::assertArrayNotHasKey('ES006C', array_flip(self::getContainer()->get(Catalog::class)->shipped()['temp_max_city']->geo));
    }

    public function testNothingIsKeptWhenOpenMeteoRefusesOrTheCityIsNotOffered(): void
    {
        $this->openMeteo(new MockResponse('{"error":true,"reason":"Daily API request limit exceeded."}', ['http_code' => 429]));
        $this->client->request('POST', '/import/city', ['city' => 'ES006C'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('ul.problem', 'free quota is used up');

        $this->client->request('POST', '/import/city', ['city' => 'NO001C'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/import/city', ['city' => 'ES006C'], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], self::getContainer()->get(\App\Import\ImportStore::class)->cities());
    }

    public function testAnMcpClientListsTheCitiesThenAddsOne(): void
    {
        $this->openMeteo(new MockResponse((string) json_encode(self::response())));
        $tools = self::getContainer()->get(\App\Mcp\ImportTools::class);

        $list = $tools->addCity();
        self::assertFalse($list->isError);
        self::assertStringContainsString('`ES006C`: Malaga (ES)', self::text($list));
        self::assertStringNotContainsString('ES004C', self::text($list), 'Seville is in the catalog already.');

        // A city of the catalog, or one the list does not have: refused before any request.
        self::assertTrue($tools->addCity('ES004C')->isError);
        self::assertTrue($tools->addCity('ZZ999C')->isError);
        self::assertCount(0, $this->asked);

        $added = $tools->addCity('ES006C');
        self::assertFalse($added->isError);
        self::assertSame(['city' => 'ES006C'], $added->structuredContent);
        // The cell the answer names is one the server can now prove.
        self::assertStringContainsString('{"type":"measured","dataset":"temp_max_city","geo":"ES006C","time":"M09"}', self::text($added));
        $proof = self::getContainer()->get(\App\Proof\Resolver::class)->measured(new \App\Layout\Measured('measured', 'temp_max_city', 'ES006C', 'M09'));
        self::assertInstanceOf(\App\Proof\MeasuredProof::class, $proof);
        self::assertTrue($tools->addCity('ES006C')->isError, 'Never twice.');
    }

    public function testACityWhosePointIsOutsideItsCentreSaysSoWhereverItsFiguresAre(): void
    {
        $this->openMeteo(new MockResponse((string) json_encode(self::response())));

        $this->client->request('POST', '/import/city', ['city' => 'IT003C'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.added', 'known to lie well outside its centre');

        $proof = self::getContainer()->get(\App\Proof\Resolver::class)->measured(new \App\Layout\Measured('measured', 'temp_max_city', 'IT003C', 'M09'));
        self::assertInstanceOf(\App\Proof\MeasuredProof::class, $proof);
        $this->client->request('GET', '/proof/'.$proof->token());
        self::assertSelectorTextContains('.imported', 'known to lie well outside its centre');
    }

    public function testAFullStoreSaysSoToTheVisitorAndToAnMcpClient(): void
    {
        $store = self::getContainer()->get(\App\Import\ImportStore::class);
        for ($i = 0; $i < \App\Import\ImportStore::MAX_CITIES; ++$i) {
            self::assertTrue($store->addCity(\sprintf('ZZ%03dC', $i), 'Somewhere', 'latitude=1.0&longitude=1.0', []));
        }

        $this->client->request('GET', '/import');
        self::assertSelectorTextContains('form.city .note', 'This Go Figure already keeps as many added cities as it may.');
        $tools = self::getContainer()->get(\App\Mcp\ImportTools::class);
        self::assertStringContainsString('already keeps 30 added cities', self::text($tools->addCity()));
        self::assertStringContainsString('already keeps 30 added cities: no room for one more.', self::text($tools->addCity('ES006C')));
    }

    public function testAFullStoreRefusesBeforeAskingOpenMeteo(): void
    {
        $this->openMeteo(new MockResponse((string) json_encode(self::response())));
        $store = self::getContainer()->get(\App\Import\ImportStore::class);
        for ($i = 0; $i < \App\Import\ImportStore::MAX_CITIES; ++$i) {
            self::assertTrue($store->addCity(\sprintf('ZZ%03dC', $i), 'Somewhere', 'latitude=1.0&longitude=1.0', []));
        }

        $this->client->request('POST', '/import/city', ['city' => 'ES006C'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->asked);
    }

    private static function text(\Mcp\Schema\Result\CallToolResult $result): string
    {
        $content = $result->content[0] ?? null;
        self::assertInstanceOf(\Mcp\Schema\Content\TextContent::class, $content);

        return $content->text;
    }

    private function openMeteo(MockResponse $response): void
    {
        $asked = $this->asked;
        $container = self::getContainer();
        $container->set(CityWeather::class, new CityWeather(
            new MockHttpClient(static function (string $method, string $url) use ($asked, $response): MockResponse {
                $asked->append($url);

                return $response;
            }),
            $container->get(\App\Import\ImportStore::class),
            $container->get(Catalog::class),
            $container->get(ClockInterface::class),
            (string) $container->getParameter('app.cities_file'),
        ));
    }

    /**
     * Thirty years of days: by default the maximum is 10 + the month, and it rains 1 mm a day.
     *
     * @param (\Closure(\DateTimeImmutable): ?float)|null $temperature
     * @param (\Closure(\DateTimeImmutable): ?float)|null $rain
     *
     * @return array<string, mixed>
     */
    private static function response(?\Closure $temperature = null, ?\Closure $rain = null, ?int $days = null): array
    {
        $temperature ??= static fn (\DateTimeImmutable $d): float => 10.0 + (int) $d->format('n');
        $rain ??= static fn (\DateTimeImmutable $d): float => 1.0;
        $time = $max = $sum = [];
        for ($day = new \DateTimeImmutable('1991-01-01'); $day <= new \DateTimeImmutable('2020-12-31'); $day = $day->modify('+1 day')) {
            $time[] = $day->format('Y-m-d');
            $max[] = $temperature($day);
            $sum[] = $rain($day);
            if (null !== $days && \count($time) >= $days) {
                break;
            }
        }

        return ['latitude' => 36.77, 'longitude' => -4.42, 'elevation' => 20.0,
            'daily_units' => ['time' => 'iso8601', 'temperature_2m_max' => '°C', 'precipitation_sum' => 'mm'],
            'daily' => ['time' => $time, 'temperature_2m_max' => $max, 'precipitation_sum' => $sum]];
    }
}
