<?php

declare(strict_types=1);

namespace App\Import;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Eurostat, one of the sources the server reads on a visitor's behalf (/import).
 *
 * Against server-side request forgery: one fixed host, no redirect, a dataset code checked before
 * any request, dimension codes checked against the dataset's own before the data is asked for, and a
 * time limit and a size limit on every answer.
 */
final readonly class EurostatApi
{
    /** A Eurostat dataset code: tour_occ_arnat, prc_hicp_aind. */
    public const string CODE = '/^[a-z][a-z0-9_]{2,39}$/D';
    /** A code of a dimension, and a code within one: c_resid, TOTAL, I551-I553, EU27_2020. */
    public const string DIMENSION = '/^[a-z][a-z0-9_]{0,29}$/D';
    public const string CATEGORY = '/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,39}$/D';
    private const string BASE = 'https://ec.europa.eu/eurostat/api/dissemination/';
    /** Below what PHP can decode within its memory limit: a larger answer is refused, not read. */
    private const int MAX_BYTES = 5_000_000;
    /**
     * The families of datasets a visitor may add, by the first word of their code: what countries
     * produce, trade, build, move, grow and measure. Rule 5 of the project keeps the rest out: no
     * sensitive subject on a page of Go Figure, so no family about people (health, migration,
     * justice, living conditions, the labour force, education, demography).
     */
    public const array FAMILIES = [
        // Travel: tourism, transport, prices.
        'tour', 'avia', 'rail', 'road', 'mar', 'iww', 'tran', 'pipe', 'prc',
        // Environment, energy, land.
        'env', 'nrg', 'cei', 'cli', 'aei', 'lan', 'for',
        // Culture and sport.
        'cult', 'sprt',
        // The economy: national accounts, trade, exchange and interest rates, short-term indicators.
        'nama', 'namq', 'nasa', 'nasq', 'naio', 'naida', 'naidq', 'naidsa', 'naidsq', 'ext', 'bop', 'ert', 'irt', 'ei', 'sts',
        // Businesses, innovation, research, the digital economy.
        'sbs', 'bd', 'bs', 'inn', 'iss', 'rd', 'pat', 'gba', 'isoc', 'fats', 'gvc', 'gloe', 'egr', 'egi', 'dt', 'acf',
        // Agriculture and fisheries.
        'apro', 'aact', 'apri', 'agr', 'ef', 'orch', 'vit', 'org', 'fish',
    ];
    /** Outside these families, the datasets taken one by one: the population and house prices. */
    public const array DATASETS = ['demo_pjan', 'tipsho20', 'tipsho40'];
    /** Within the families, left out for their subject: accident deaths (set aside since 5/11, for the tone). */
    public const array LEFT_OUT = ['tran_sf_'];
    /**
     * A second net, on the title: a dataset of an accepted family whose title holds one of these
     * words is about people after all ("Victims in road accidents", "Researchers by country of
     * citizenship"). The same net is used on the label of every code chosen and on the words of a
     * proposal. It errs on the side of caution ("enterprise deaths" is refused too).
     */
    private const string SENSITIVE = '/\\b(deaths?|died|mortality|suicide\\w*|violence|asylum|crimes?|offences?|abortion\\w*|poverty|deprivation|disabilit\\w*|victims?|killed|homicide\\w*|drugs?|disease\\w*|cancer|hiv|citizenship|refugee\\w*|migra\\w*|immigra\\w*|emigra\\w*|prison\\w*|religio\\w*|ethnic\\w*|births?|fertility|divorce\\w*|marriage\\w*|injur\\w*|fatal\\w*|accidents?|harass\\w*|discriminat\\w*|homeless\\w*|life expectancy|foreign-born|unemploy\\w*|health\\w*|obes\\w*|smok\\w*|tobacco|weapon\\w*|arms|military|defence|election\\w*|perception|narcotic\\w*|undernourish\\w*|malnutrition|stunting|hunger|food insecurity|slums?|sanitation|child|children|women|female|gender|brib\\w*|corrupt\\w*|theft|conflict\\w*|displaced|terror\\w*|remittances?|employ\\w*|labou?r)\\b/i';

    public function __construct(
        private HttpClientInterface $http,
        private CacheInterface $cache,
    ) {
    }

    /**
     * Whether a visitor may add this dataset: its family by its code, then its title when it is
     * known (the table of contents, the dataset itself).
     */
    public static function allowed(string $code, ?string $title = null): bool
    {
        foreach (self::LEFT_OUT as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return false;
            }
        }
        if (!\in_array($code, self::DATASETS, true) && !\in_array(explode('_', $code)[0], self::FAMILIES, true)) {
            return false;
        }

        return null === $title || !self::sensitive($title);
    }

    /** Whether a title is about people's lives after all: the second net against sensitive subjects, for every source. */
    public static function sensitive(string $title): bool
    {
        return 1 === preg_match(self::SENSITIVE, $title);
    }

    /**
     * The datasets a visitor may add whose title or code holds the words of the query, best first: from
     * Eurostat's table of contents, read once a day.
     *
     * @return list<array{code: string, title: string, from: string, to: string}>
     *
     * @throws EurostatUnavailable
     */
    public function search(string $query, int $limit = 8): array
    {
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($query)) ?: [],
            static fn (string $word): bool => mb_strlen($word) >= 3,
        ));
        if ([] === $words) {
            return [];
        }

        $scored = [];
        foreach ($this->contents() as $dataset) {
            if (!self::allowed($dataset['code'], $dataset['title'])) {
                continue;
            }
            $title = mb_strtolower($dataset['title']);
            $score = \in_array($dataset['code'], $words, true) ? 10 : 0;
            foreach ($words as $word) {
                $score += str_contains($title, $word) || str_contains($dataset['code'], $word) ? 1 : 0;
            }
            if ($score > 0) {
                $scored[] = [$score, $dataset];
            }
        }
        usort($scored, static fn (array $a, array $b): int => [$b[0], $a[1]['code']] <=> [$a[0], $b[1]['code']]);

        return array_map(static fn (array $s): array => $s[1], \array_slice($scored, 0, $limit));
    }

    /**
     * The dimensions of a dataset and their codes, read from its latest period only.
     *
     * @throws DatasetNotFound     when Eurostat has no such dataset
     * @throws DatasetNotByPlace   when it is not given by place and period
     * @throws DatasetNotAllowed   when its family or its title is one Go Figure leaves out
     * @throws EurostatUnavailable
     */
    public function structure(string $code): DatasetStructure
    {
        if (1 !== preg_match(self::CODE, $code)) {
            throw new DatasetNotFound($code);
        }
        // Not even read: its title and labels would be shown on the site (no sensitive subject).
        if (!self::allowed($code)) {
            throw new DatasetNotAllowed($code);
        }

        // Kept an hour: a visitor reads it, then imports; a reload costs no download.
        $structure = $this->cache->get('eurostat_structure_'.$code, function (ItemInterface $item) use ($code): DatasetStructure {
            $item->expiresAfter(3600);

            return DatasetStructure::fromJsonStat($code, $this->get('statistics/1.0/data/'.$code, ['format' => 'JSON', 'lang' => 'EN', 'lastTimePeriod' => '1'], $code));
        });
        // Its family is accepted: its title says whether it is about people after all.
        if (!self::allowed($code, $structure->label)) {
            throw new DatasetNotAllowed($code);
        }

        return $structure;
    }

    /**
     * Every cell of a dataset for some places, with every other dimension frozen (JSON-stat).
     *
     * @param array<string, string> $dimensions dimension => code, checked against the structure
     * @param list<string>          $places
     *
     * @return array<string, mixed>
     *
     * @throws EurostatUnavailable
     */
    public function data(string $code, array $dimensions, array $places): array
    {
        if (1 !== preg_match(self::CODE, $code)) {
            throw new DatasetNotFound($code);
        }
        $query = ['format' => 'JSON', 'lang' => 'EN'];
        foreach ($dimensions as $dimension => $category) {
            if (1 !== preg_match(self::DIMENSION, $dimension) || 1 !== preg_match(self::CATEGORY, $category)) {
                throw new \InvalidArgumentException(\sprintf('Not a dimension code: %s=%s.', $dimension, $category));
            }
            $query[$dimension] = $category;
        }
        // Several values of one parameter: geo=FR&geo=ES, which http_build_query does not write.
        $geo = implode('', array_map(static fn (string $place): string => '&geo='.rawurlencode($place), $places));

        return $this->get('statistics/1.0/data/'.$code, $query, $code, $geo);
    }

    /**
     * @return list<array{code: string, title: string, from: string, to: string}>
     */
    private function contents(): array
    {
        return $this->cache->get('eurostat_contents', function (ItemInterface $item): array {
            $item->expiresAfter(86400);
            $datasets = [];
            foreach (explode("\n", $this->text('catalogue/toc/txt', ['lang' => 'en'])) as $line) {
                $fields = str_getcsv($line, "\t", '"', '');
                // A dataset sits in several folders of the table: it is listed once.
                if (\count($fields) >= 7 && 'dataset' === $fields[2] && 1 === preg_match(self::CODE, (string) $fields[1]) && !isset($datasets[$fields[1]])) {
                    $datasets[(string) $fields[1]] = ['code' => (string) $fields[1], 'title' => trim((string) $fields[0]), 'from' => (string) $fields[5], 'to' => (string) $fields[6]];
                }
            }

            return array_values($datasets);
        });
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function get(string $path, array $query, string $code, string $more = ''): array
    {
        try {
            $response = $this->request($path, $query, $more);
            if (\in_array($response->getStatusCode(), [400, 404], true)) {
                throw new DatasetNotFound($code);
            }

            return $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new EurostatUnavailable($e->getMessage(), previous: $e);
        }
    }

    /**
     * @param array<string, string> $query
     */
    private function text(string $path, array $query): string
    {
        try {
            return $this->request($path, $query)->getContent();
        } catch (ExceptionInterface $e) {
            throw new EurostatUnavailable($e->getMessage(), previous: $e);
        }
    }

    /**
     * @param array<string, string> $query
     */
    private function request(string $path, array $query, string $more = ''): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $this->http->request('GET', self::BASE.$path.'?'.http_build_query($query).$more, [
            'max_redirects' => 0,
            // Uncompressed, so the size limit counts what PHP will decode.
            'headers' => ['Accept-Encoding' => 'identity'],
            'timeout' => 20,
            'max_duration' => 60,
            // Throwing here cancels the transfer: an answer too large is never read whole.
            'on_progress' => static function (int $downloaded): void {
                if ($downloaded > self::MAX_BYTES) {
                    throw new EurostatUnavailable('The answer of Eurostat is too large.');
                }
            },
        ]);
    }
}
