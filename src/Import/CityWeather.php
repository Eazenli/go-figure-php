<?php

declare(strict_types=1);

namespace App\Import;

use App\Catalog\Catalog;
use App\Catalog\Granularity;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "Add a city": the weather of one more city, on this machine (the extended mode). The visitor only
 * picks a city of the list (data/cities.csv: Eurostat code, name, point); the server asks Open-Meteo
 * once for its daily values (the visitor's own quota of the free API), computes the normals as for
 * the shipped cities, and stores them with the date. The proof of each figure links to the request
 * that returns the same daily values.
 *
 * One fixed host, a point that comes from our own list, no redirect, a size and a time limit.
 */
final readonly class CityWeather
{
    private const string ARCHIVE = 'https://archive-api.open-meteo.com/v1/archive';
    private const int MAX_BYTES = 2_000_000;

    public function __construct(
        private HttpClientInterface $http,
        private ImportStore $store,
        private Catalog $catalog,
        private ClockInterface $clock,
        #[Autowire('%app.cities_file%')]
        private string $file,
    ) {
    }

    /** A city of the list whose point is known to lie well outside its centre (the note of cities.csv). */
    public function offCentre(string $code): bool
    {
        return $this->list()[$code]['off_centre'] ?? false;
    }

    /** No room left: this Go Figure keeps as many added cities as it may. */
    public function full(): bool
    {
        return \count($this->store->cities()) >= ImportStore::MAX_CITIES;
    }

    /**
     * The cities that can still be added: in the list, not flagged for a misplaced point, not in the
     * catalog yet.
     *
     * @return array<string, array{name: string, country: string, place: string, off_centre: bool}> by code
     */
    public function addable(): array
    {
        if ($this->full()) {
            return [];
        }
        $known = [];
        foreach ($this->entries() as $entry) {
            $known += array_flip($entry->geo);
        }
        $cities = [];
        foreach ($this->list() as $code => $city) {
            if (!isset($known[$code])) {
                $cities[$code] = $city;
            }
        }

        return $cities;
    }

    /**
     * @return string the name of the city added
     *
     * @throws ImportRefused       nothing kept
     * @throws EurostatUnavailable when Open-Meteo does not answer (or its quota is used up): nothing kept
     */
    public function add(string $code): string
    {
        // Before any request: a city that cannot be kept must not cost the visitor's quota.
        if ($this->full()) {
            throw new ImportRefused([['This Go Figure already keeps %max% added cities: no room for one more.', ['%max%' => (string) ImportStore::MAX_CITIES]]]);
        }
        $city = $this->addable()[$code] ?? null;
        if (null === $city) {
            throw new ImportRefused([['This city cannot be added: it is not in the list, or it is there already.', []]]);
        }
        $entries = $this->entries();
        if ([] === $entries) {
            throw new ImportRefused([['This Go Figure has no weather dataset to add a city to.', []]]);
        }

        try {
            $response = $this->http->request('GET', self::ARCHIVE.'?'.$city['place'].'&'.http_build_query([
                'start_date' => Normals::START, 'end_date' => Normals::END,
                'daily' => implode(',', array_keys(Normals::VARIABLES)), 'models' => 'cerra', 'timezone' => 'GMT',
            ]), [
                // Under PHP's own time limit: a slow answer ends as "does not answer", not as a fatal error.
                'max_redirects' => 0, 'timeout' => 10, 'max_duration' => 20,
                'headers' => ['Accept-Encoding' => 'identity'],
                'on_progress' => static function (int $downloaded): void {
                    if ($downloaded > self::MAX_BYTES) {
                        throw new EurostatUnavailable('The answer of Open-Meteo is too large.');
                    }
                },
            ]);
            if (429 === $response->getStatusCode()) {
                throw new ImportRefused([['Open-Meteo answers that its free quota is used up from this address: try again in an hour, or tomorrow.', []]]);
            }
            $data = $response->toArray();
            Normals::check($data);
        } catch (ExceptionInterface|\UnexpectedValueException $e) {
            throw new EurostatUnavailable($e->getMessage(), previous: $e);
        }

        $today = $this->clock->now()->format('Y-m-d');
        $rows = [];
        foreach ($entries as $entry) {
            parse_str((string) parse_url($entry->url, \PHP_URL_QUERY), $query);
            $variable = \is_string($query['daily'] ?? null) ? $query['daily'] : '';
            if (!isset(Normals::VARIABLES[$variable])) {
                continue;
            }
            foreach (Normals::of($data, $variable) as $month => $value) {
                $rows[] = [$entry->id, $code, $month, $entry->unitCode, $value, '', $today, $today];
            }
        }
        if ([] === $rows) {
            throw new ImportRefused([['Open-Meteo has too few daily values for this city.', []]]);
        }
        if (!$this->store->addCity($code, $city['name'], $city['place'], $rows)) {
            throw new ImportRefused([['This city is there already, or this Go Figure already keeps %max% added cities.', ['%max%' => (string) ImportStore::MAX_CITIES]]]);
        }
        $this->catalog->reset();

        return $city['name'];
    }

    /**
     * @return list<\App\Catalog\CatalogEntry> the climate normals of Open-Meteo, as the catalog has them now
     */
    private function entries(): array
    {
        return array_values(array_filter(
            $this->catalog->all(),
            static fn ($entry): bool => 'open_meteo' === $entry->provider && Granularity::MonthOfYear === $entry->time->granularity,
        ));
    }

    /**
     * @return array<string, array{name: string, country: string, place: string, off_centre: bool}>
     */
    private function list(): array
    {
        $cities = [];
        $handle = is_file($this->file) ? fopen($this->file, 'r') : false;
        if (false === $handle) {
            return [];
        }
        $header = array_map(strval(...), fgetcsv($handle, escape: '') ?: []);
        while (false !== $row = fgetcsv($handle, escape: '')) {
            if (\count($row) !== \count($header)) {
                continue;
            }
            $city = array_combine($header, $row) + ['code' => '', 'name' => '', 'country' => '', 'latitude' => '', 'longitude' => '', 'note' => ''];
            // A point noted as misplaced is not offered: its normals would be wrong.
            if (1 !== preg_match('/^[A-Z]{2}\d{3}C$/D', (string) $city['code']) || str_contains((string) $city['note'], 'before downloading')
                || 1 !== preg_match('/^-?\d{1,2}\.\d{1,4}$/D', (string) $city['latitude']) || 1 !== preg_match('/^-?\d{1,3}\.\d{1,4}$/D', (string) $city['longitude'])) {
                continue;
            }
            $cities[(string) $city['code']] = ['name' => (string) $city['name'], 'country' => (string) $city['country'], 'place' => \sprintf('latitude=%s&longitude=%s', $city['latitude'], $city['longitude']),
                // A point known to lie well outside the centre: offered, and said wherever its figures are.
                'off_centre' => str_contains((string) $city['note'], 'check on a map')];
        }
        fclose($handle);

        return $cities;
    }
}
