<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Attribution;
use App\Catalog\Granularity;
use App\Catalog\InvalidCatalog;
use App\Layout\Op;
use App\Tests\Support\CatalogFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class CatalogTest extends TestCase
{
    private const string SHIPPED = __DIR__.'/../../catalog';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/go-figure-catalog-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/entries', 0o777, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    /**
     * The shipped entries, written by hand: a broken file fails here, with its errors listed.
     */
    public function testEveryShippedEntryIsValid(): void
    {
        $entries = CatalogFixture::loader()->load(self::SHIPPED);

        self::assertNotEmpty($entries);
        foreach ($entries as $id => $entry) {
            self::assertSame($id, $entry->id);
            self::assertNotEmpty($entry->operations, $id);
        }
    }

    public function testAnEntryIsReadWithItsSourceAndCoverage(): void
    {
        $entry = CatalogFixture::loader()->load(CatalogFixture::DIR)['nights_total'];

        self::assertSame('Nights spent in tourist accommodation', $entry->title);
        self::assertSame('tour_occ_ninat', $entry->sourceDataset);
        self::assertSame(['c_resid' => 'TOTAL', 'nace_r2' => 'I551-I553', 'unit' => 'NR'], $entry->dimensions);
        self::assertSame(['AT', 'EL', 'ES', 'FR', 'IT', 'MT', 'NL', 'PT'], $entry->geo);
        self::assertSame(Granularity::Annual, $entry->time->granularity);
        self::assertSame(['2019', '2025'], [$entry->time->from, $entry->time->to]);
        self::assertSame([Op::Change, Op::ChangePct, Op::Share, Op::Rank], $entry->operations);
        self::assertSame('https://ec.europa.eu/eurostat/databrowser/view/tour_occ_ninat/default/table', $entry->source('FR')->url);
        self::assertFalse($entry->covers('DE'));
    }

    public function testASourceUrlThatVariesByPlaceUsesTheSourceCode(): void
    {
        $this->write('temp_max', self::climate());

        $entry = CatalogFixture::loader()->load($this->dir.'/entries')['temp_max'];

        self::assertSame('https://cckpapi.worldbank.org/cckp/v1/tasmax/GRC?_format=json', $entry->source('EL')->url);
        self::assertSame(['M01', 'M12', '1991-2020'], [$entry->time->from, $entry->time->to, $entry->time->normal]);
    }

    public function testACityHasItsNameItsMethodAndWhatItsSourceAsksToShow(): void
    {
        $catalog = CatalogFixture::catalog($this->dir.'/cache');
        $entry = $catalog->get('temp_max_city');
        self::assertNotNull($entry);

        self::assertSame(['ES004C' => 'Seville', 'PT001C' => 'Lisbon'], $entry->names);
        self::assertStringStartsWith('Go Figure averages the daily maximum temperature', (string) $entry->method);
        self::assertEquals([new Attribution('Weather data by Open-Meteo.com', 'https://open-meteo.com/'), new Attribution('Contains modified Copernicus Climate Change Service information')], $entry->attributions);
        self::assertStringStartsWith('https://archive-api.open-meteo.com/v1/archive?latitude=37.393&longitude=-5.956&start_date=', $entry->source('ES004C')->url);
        self::assertSame('Seville', $catalog->placeName('ES004C'));
        self::assertNull($catalog->placeName('ES'));
        // An entry published by the source itself has no method.
        self::assertNull($catalog->get('nights_total')?->method);
    }

    public function testTwoEntriesCannotNameAPlaceDifferently(): void
    {
        $city = Yaml::parseFile(CatalogFixture::DIR.'/temp_max_city.yaml');
        $this->write('temp_max_city', $city);
        $this->write('rain_city', ['id' => 'rain_city', 'places' => ['ES004C' => 'Sevilla', 'PT001C' => 'Lisbon']] + $city);

        try {
            CatalogFixture::loader()->load($this->dir.'/entries');
            self::fail('The catalog was accepted.');
        } catch (InvalidCatalog $e) {
            self::assertStringContainsString('temp_max_city.yaml: places.ES004C: "Seville" is "Sevilla" in rain_city.yaml: use one name.', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): array<mixed>, string}>
     */
    public static function invalidEntries(): iterable
    {
        yield 'a typo in a key' => [static fn (array $e): array => ['pitfals' => $e['pitfalls']] + $e, 'pitfals: This field was not expected.'];
        yield 'a missing scope' => [static function (array $e): array {
            unset($e['scope']);

            return $e;
        }, 'scope: This field is missing.'];
        yield 'an id that is not the file name' => [static fn (array $e): array => ['id' => 'nights_hotels'] + $e, 'id: "nights_hotels" must match the file name.'];
        yield 'a digit in the title' => [static fn (array $e): array => ['title' => ['en' => 'Nights per 1000 inhabitants']] + $e, 'title.en: No digits, numbers in words or number signs in a title'];
        yield 'a number in words in the title' => [static fn (array $e): array => ['title' => ['en' => 'Nights per thousand inhabitants']] + $e, 'title.en: No digits, numbers in words or number signs in a title'];
        yield 'an id with capitals' => [static fn (array $e): array => ['id' => 'Nights'] + $e, 'id: Use lower case letters'];
        yield 'a source page over http' => [static function (array $e): array {
            $e['source']['url'] = 'http://example.org/';

            return $e;
        }, 'source.url: This is not an https URL.'];
        yield 'a place given by its name' => [static fn (array $e): array => ['geo' => ['France']] + $e, 'geo.0: This is not a place code'];
        yield 'a place listed twice' => [static fn (array $e): array => ['geo' => ['FR', 'FR']] + $e, 'geo: This collection should contain only unique elements.'];
        yield 'no place at all' => [static fn (array $e): array => ['geo' => []] + $e, 'geo: This collection should contain 1 element or more.'];
        yield 'periods in the wrong order' => [static fn (array $e): array => ['time' => ['granularity' => 'annual', 'from' => 2025, 'to' => 2019]] + $e, 'time.from: The first period comes after the last one.'];
        yield 'a month in an annual entry' => [static fn (array $e): array => ['time' => ['granularity' => 'annual', 'from' => '2019-01', 'to' => 2025]] + $e, 'time.from: This is not a year such as 2008.'];
        yield 'an unknown granularity' => [static fn (array $e): array => ['time' => ['granularity' => 'weekly', 'from' => 2019, 'to' => 2025]] + $e, 'time.granularity: The value you selected is not a valid choice.'];
        yield 'a climate normal with a range' => [static fn (array $e): array => ['time' => ['granularity' => 'month_of_year', 'normal' => '1991-2020', 'from' => 2019]] + $e, 'time.from: A climate normal covers M01 to M12'];
        yield 'an unknown theme' => [static fn (array $e): array => ['theme' => 'beaches'] + $e, 'theme: The value you selected is not a valid choice.'];
        yield 'an unknown operation' => [static fn (array $e): array => ['operations' => ['change', 'average']] + $e, 'operations: One or more of the given values is invalid.'];
        yield 'a place missing from geo_map' => [static fn (array $e): array => ['geo' => ['EL', 'ES']] + self::climate(), 'geo_map: The source URL contains {place}: add these places to geo_map: ES.'];
        yield 'the placeholder of before' => [static fn (array $e): array => ['source' => ['url' => 'https://example.org/{iso3}'] + self::climate()['source']] + self::climate(), 'source.url: The placeholder of a place is now {place}.'];
        yield 'a city without its name' => [static fn (array $e): array => ['geo' => ['FR', 'ES004C']] + $e, 'places: A city needs its English name: add these places to places: ES004C.'];
        yield 'a name for a place of another entry' => [static fn (array $e): array => ['places' => ['ES004C' => 'Seville']] + $e, 'places: These places are not in geo: ES004C.'];
        yield 'a new name for a country' => [static fn (array $e): array => ['places' => ['EL' => 'Hellas']] + $e, 'places: Only a city takes a name here: EL is named by the server.'];
        yield 'a place name with a number' => [static fn (array $e): array => ['geo' => ['FR', 'ES004C'], 'places' => ['ES004C' => 'Seville 2']] + $e, 'places.ES004C: No digits'];
        yield 'an attribution link that is not https' => [static fn (array $e): array => ['source' => ['attribution' => [['text' => 'Data by Example', 'url' => 'http://example.org/']]] + $e['source']] + $e, 'source.attribution.0.url: This is not an https URL.'];
        yield 'an attribution with a year' => [static fn (array $e): array => ['source' => ['attribution' => [['text' => 'Generated using Copernicus information 2022']]] + $e['source']] + $e, 'source.attribution.0.text: No digits'];
        yield 'a unit in thousands without its scale' => [static fn (array $e): array => ['unit' => ['code' => 'THS_NIT', 'label' => 'nights']] + $e, 'unit.label: The unit THS_NIT has a scale: the label must say "thousand"'];
        yield 'million euro without its scale' => [static fn (array $e): array => ['unit' => ['code' => 'CLV15_MEUR', 'label' => 'euro, chain-linked volumes']] + $e, 'unit.label: The unit CLV15_MEUR has a scale: the label must say "million"'];
        yield 'thousand tonnes of oil without its scale' => [static fn (array $e): array => ['unit' => ['code' => 'KTOE', 'label' => 'tonnes of oil equivalent']] + $e, 'unit.label: The unit KTOE has a scale: the label must say "thousand"'];
        yield 'a rate without its scale' => [static fn (array $e): array => ['unit' => ['code' => 'P_THAB', 'label' => 'nights per inhabitant']] + $e, 'unit.label: The unit P_THAB has a scale: the label must say "thousand"'];
        yield 'a list instead of an entry' => [static fn (array $e): array => [$e['id']], 'id: This field is missing.'];
    }

    /**
     * @param \Closure(array<string, mixed>): array<mixed> $edit
     */
    #[DataProvider('invalidEntries')]
    public function testAnInvalidEntryIsReportedWithItsFileAndField(\Closure $edit, string $error): void
    {
        $entry = Yaml::parseFile(CatalogFixture::DIR.'/nights_total.yaml');
        $this->write('nights_total', $edit($entry));

        try {
            CatalogFixture::loader()->load($this->dir.'/entries');
            self::fail('The catalog was accepted.');
        } catch (InvalidCatalog $e) {
            self::assertStringContainsString('nights_total.yaml: '.$error, $e->getMessage());
        }
    }

    public function testEveryBrokenFileIsListedAtOnce(): void
    {
        file_put_contents($this->dir.'/entries/broken.yaml', "id: [unclosed\n");
        $this->write('nights_total', ['geo' => []] + Yaml::parseFile(CatalogFixture::DIR.'/nights_total.yaml'));

        try {
            CatalogFixture::loader()->load($this->dir.'/entries');
            self::fail('The catalog was accepted.');
        } catch (InvalidCatalog $e) {
            self::assertCount(2, $e->errors);
            self::assertStringStartsWith('broken.yaml: ', $e->errors[0]);
            self::assertStringStartsWith('nights_total.yaml: geo: ', $e->errors[1]);
        }
    }

    public function testAUnitLabelThatStatesTheScaleIsAccepted(): void
    {
        $entry = Yaml::parseFile(CatalogFixture::DIR.'/nights_total.yaml');
        $this->write('nights_total', ['unit' => ['code' => 'THS_NIT', 'label' => 'thousand nights']] + $entry);

        self::assertSame('thousand nights', CatalogFixture::loader()->load($this->dir.'/entries')['nights_total']->unitLabel);
    }

    public function testAnEmptyFileIsReportedNotFatal(): void
    {
        file_put_contents($this->dir.'/entries/empty.yaml', "# nothing yet\n");

        $this->expectException(InvalidCatalog::class);
        $this->expectExceptionMessage('empty.yaml: (entry): This file is empty.');

        CatalogFixture::loader()->load($this->dir.'/entries');
    }

    public function testTheTestCatalogHasAClimateNormalWithItsSourcePage(): void
    {
        $entry = CatalogFixture::loader()->load(CatalogFixture::DIR)['temp_max'];

        self::assertSame('https://cckpapi.worldbank.org/cckp/v1/tasmax/FRA?_format=json', $entry->source('FR')->url);
        self::assertSame(['M01', 'M02', 'M03', 'M04', 'M05', 'M06', 'M07', 'M08', 'M09', 'M10', 'M11', 'M12'], $entry->time->periods());
    }

    public function testAMissingDirectoryIsAnError(): void
    {
        $this->expectException(InvalidCatalog::class);
        $this->expectExceptionMessage('No catalog directory');

        CatalogFixture::loader()->load($this->dir.'/nowhere');
    }

    public function testAnEditedEntryIsReadAgainInDebugMode(): void
    {
        $entry = Yaml::parseFile(CatalogFixture::DIR.'/nights_total.yaml');
        $this->write('nights_total', $entry);
        self::assertSame(['AT', 'EL', 'ES', 'FR', 'IT', 'MT', 'NL', 'PT'], CatalogFixture::catalog($this->dir.'/cache', $this->dir.'/entries')->get('nights_total')?->geo);

        $this->write('nights_total', ['geo' => ['FR']] + $entry);
        touch($this->dir.'/entries/nights_total.yaml', time() + 2); // a later edit, as the cache sees it
        self::assertSame(['FR'], CatalogFixture::catalog($this->dir.'/cache', $this->dir.'/entries')->get('nights_total')?->geo);
    }

    /**
     * The periods and places a page may show (a note's period, the echo of a refused cell): only those
     * the entries have, so that a period cannot carry another number.
     */
    public function testOnlyPeriodsAndPlacesOfTheEntriesAreKnown(): void
    {
        $this->write('nights_total', Yaml::parseFile(CatalogFixture::DIR.'/nights_total.yaml'));
        $catalog = CatalogFixture::catalog($this->dir.'/cache', $this->dir.'/entries');

        $known = array_filter(['2018', '2019', '2025', '2026', '4576', '2024-12', 'M12', '20x4', ''], $catalog->knowsPeriod(...));
        self::assertSame(['2019', '2025'], array_values($known), 'An annual entry from 2019 to 2025: no month, no month of the year.');
        self::assertTrue($catalog->knowsPlace('FR'));
        self::assertFalse($catalog->knowsPlace('EU27_2020'));

        $this->write('temp_max', self::climate());
        touch($this->dir.'/entries/temp_max.yaml', time() + 2);
        self::assertTrue(CatalogFixture::catalog($this->dir.'/cache', $this->dir.'/entries')->knowsPeriod('M12'), 'A climate normal has months of the year.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function climate(): array
    {
        return [
            'id' => 'temp_max',
            'title' => ['en' => 'Average daily maximum temperature'],
            'source' => [
                'provider' => 'worldbank_cckp',
                'dataset' => 'tasmax',
                'url' => 'https://cckpapi.worldbank.org/cckp/v1/tasmax/{place}?_format=json',
                'licence' => 'World Bank CCKP, CC BY 4.0.',
            ],
            'geo_map' => ['EL' => 'GRC', 'FR' => 'FRA'],
            'geo' => ['EL', 'FR'],
            'time' => ['granularity' => 'month_of_year', 'normal' => '1991-2020'],
            'unit' => ['code' => 'degC', 'label' => '°C'],
            'scope' => ['en' => 'Country-wide average.'],
            'theme' => 'weather',
            'operations' => ['rank'],
        ];
    }

    /**
     * @param array<mixed> $entry
     */
    private function write(string $id, array $entry): void
    {
        file_put_contents($this->dir.'/entries/'.$id.'.yaml', Yaml::dump($entry, 4));
    }
}
