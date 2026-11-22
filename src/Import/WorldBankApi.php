<?php

declare(strict_types=1);

namespace App\Import;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The World Development Indicators of the World Bank, read from its open API (no key): one value
 * by country and year, for the whole world. A second source for the extended mode, with the same
 * rule as Eurostat (no sensitive subject): the topics about what countries produce, trade, build and measure, and no
 * subject about people's lives.
 */
final readonly class WorldBankApi
{
    public const string INDICATOR = '/^[A-Z][A-Z0-9]{1,5}(\.[A-Z0-9]{1,8}){1,6}$/D';
    private const string BASE = 'https://api.worldbank.org/v2/';
    /** The World Development Indicators, among the databases of the World Bank. */
    private const string SOURCE = '2';
    /**
     * The topics a visitor may add an indicator of (the World Bank's own ids): agriculture, economy
     * and growth, energy and mining, environment, financial sector, infrastructure, private sector,
     * science and technology, urban development, climate change, trade (checked against the World
     * Bank's list of topics on 2026-11-19). An indicator is taken when
     * every topic it is filed under is one of them: health, poverty, education, gender, labour and
     * social protection, aid and the public sector stay out.
     */
    public const array TOPICS = ['1', '3', '5', '6', '7', '9', '12', '14', '16', '19', '21'];
    /** Outside these topics, the indicators taken one by one: the population. */
    public const array INDICATORS = ['SP.POP.TOTL'];
    /** The years asked for: enough for a trend, small enough for one request. */
    private const string YEARS = '2000:2030';

    public function __construct(
        private HttpClientInterface $http,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @param list<string> $topics the ids of the topics the World Bank files the indicator under
     */
    public static function allowed(string $id, string $name, array $topics): bool
    {
        if (EurostatApi::sensitive($name)) {
            return false;
        }

        return \in_array($id, self::INDICATORS, true) || ([] !== $topics && [] === array_diff($topics, self::TOPICS));
    }

    /**
     * The indicators a visitor may add whose name holds the words of the query, best first.
     *
     * @return list<array{id: string, name: string}>
     *
     * @throws WorldBankUnavailable
     */
    public function search(string $query, int $limit = 8): array
    {
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [],
            static fn (string $word): bool => mb_strlen($word) >= 3,
        ));
        $scored = [];
        foreach ([] === $words ? [] : $this->indicators() as $indicator) {
            $name = mb_strtolower($indicator['name']);
            // Asked for by its id (NY.GDP.MKTP.CD): that one first.
            $score = 0 === strcasecmp($indicator['id'], trim($query)) ? 100 : 0;
            foreach ($words as $word) {
                $score += str_contains($name, $word) ? 1 : 0;
            }
            if ($score > 0) {
                // Among equals, the shorter name first: "GDP (current US$)" before its breakdowns.
                $scored[] = [$score, -mb_strlen($name), $indicator];
            }
        }
        usort($scored, static fn (array $a, array $b): int => [$b[0], $b[1], $a[2]['id']] <=> [$a[0], $a[1], $b[2]['id']]);

        return array_map(static fn (array $s): array => ['id' => $s[2]['id'], 'name' => $s[2]['name']], \array_slice($scored, 0, $limit));
    }

    /**
     * One indicator a visitor may add: its name and what the World Bank says it measures.
     *
     * @return array{id: string, name: string, note: string}
     *
     * @throws DatasetNotFound      when the World Bank has no such indicator among the WDI
     * @throws DatasetNotAllowed    when its topics or its name are ones Go Figure leaves out
     * @throws WorldBankUnavailable
     */
    public function indicator(string $id): array
    {
        if (1 !== preg_match(self::INDICATOR, $id)) {
            throw new DatasetNotFound($id);
        }
        foreach ($this->all() as $indicator) {
            if ($indicator['id'] === $id) {
                if (!self::allowed($id, $indicator['name'], $indicator['topics'])) {
                    throw new DatasetNotAllowed($id);
                }

                return ['id' => $id, 'name' => $indicator['name'], 'note' => $indicator['note']];
            }
        }

        throw new DatasetNotFound($id);
    }

    /**
     * Every value of an indicator, by country (ISO 3166 two-letter code) and year; the regions and
     * income groups the World Bank also publishes are left out.
     *
     * @return array{updated: string, cells: list<array{string, string, float}>}
     *
     * @throws WorldBankUnavailable
     */
    public function data(string $id): array
    {
        if (1 !== preg_match(self::INDICATOR, $id)) {
            throw new WorldBankUnavailable('Not an indicator id.');
        }
        $countries = $this->countries();
        [$meta, $rows] = $this->get('country/all/indicator/'.$id, ['date' => self::YEARS, 'per_page' => '20000', 'source' => self::SOURCE]);
        $cells = [];
        foreach ($rows as $row) {
            $country = $row['country']['id'] ?? null;
            $year = $row['date'] ?? null;
            $value = $row['value'] ?? null;
            if (\is_string($country) && isset($countries[$country]) && \is_string($year) && 1 === preg_match('/^\d{4}$/D', $year) && (\is_int($value) || \is_float($value))) {
                $cells[] = [$country, $year, (float) $value];
            }
        }

        return ['updated' => substr((string) ($meta['lastupdated'] ?? ''), 0, 10), 'cells' => $cells];
    }

    /**
     * The indicators a visitor may add.
     *
     * @return list<array{id: string, name: string, note: string, topics: list<string>}>
     */
    private function indicators(): array
    {
        return array_values(array_filter($this->all(), static fn (array $i): bool => self::allowed($i['id'], $i['name'], $i['topics'])));
    }

    /**
     * Every World Development Indicator, read once a day.
     *
     * @return list<array{id: string, name: string, note: string, topics: list<string>}>
     */
    private function all(): array
    {
        return $this->cache->get('worldbank_indicators', function (ItemInterface $item): array {
            $item->expiresAfter(86400);
            $indicators = [];
            foreach ($this->get('source/'.self::SOURCE.'/indicator', ['per_page' => '3000'])[1] as $row) {
                $id = $row['id'] ?? null;
                $name = $row['name'] ?? null;
                if (!\is_string($id) || !\is_string($name) || 1 !== preg_match(self::INDICATOR, $id)) {
                    continue;
                }
                $topics = [];
                foreach (\is_array($row['topics'] ?? null) ? $row['topics'] : [] as $topic) {
                    if (\is_array($topic) && isset($topic['id']) && \is_scalar($topic['id'])) {
                        $topics[] = (string) $topic['id'];
                    }
                }
                $indicators[] = ['id' => $id, 'name' => trim($name), 'note' => trim(\is_string($row['sourceNote'] ?? null) ? $row['sourceNote'] : ''), 'topics' => $topics];
            }

            return $indicators;
        });
    }

    /**
     * The countries of the World Bank, without its regions and income groups: ISO code => true.
     *
     * @return array<string, bool>
     */
    private function countries(): array
    {
        return $this->cache->get('worldbank_countries', function (ItemInterface $item): array {
            $item->expiresAfter(86400);
            $countries = [];
            foreach ($this->get('country', ['per_page' => '400'])[1] as $row) {
                $code = $row['iso2Code'] ?? null;
                if (\is_string($code) && 1 === preg_match('/^[A-Z]{2}$/D', $code) && 'NA' !== ($row['region']['id'] ?? 'NA')) {
                    $countries[$code] = true;
                }
            }

            return $countries;
        });
    }

    /**
     * @param array<string, string> $query
     *
     * @return array{array<string, mixed>, list<array<string, mixed>>} the page header and its rows
     *
     * @throws WorldBankUnavailable
     */
    private function get(string $path, array $query): array
    {
        try {
            $data = $this->http->request('GET', self::BASE.$path.'?'.http_build_query(['format' => 'json'] + $query), [
                'timeout' => 20,
                'max_duration' => 60,
                'max_redirects' => 0,
                'headers' => ['Accept' => 'application/json'],
            ])->toArray();
        } catch (ExceptionInterface $e) {
            throw new WorldBankUnavailable($e->getMessage(), previous: $e);
        }
        // [header, rows]; an error comes as one object with a "message".
        if (!array_is_list($data) || 2 !== \count($data) || !\is_array($data[0]) || !\is_array($data[1])) {
            throw new WorldBankUnavailable('Not the answer the World Bank documents.');
        }

        return [$data[0], array_values(array_filter($data[1], \is_array(...)))];
    }
}
