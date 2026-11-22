<?php

declare(strict_types=1);

namespace App\Composer;

use App\Catalog\Catalog;
use App\Catalog\CatalogEntry;
use App\Catalog\Granularity;
use App\Catalog\Places;
use App\Catalog\Theme;
use App\Data\Facts;
use App\Layout\BarChart;
use App\Layout\FigureRef;
use App\Layout\KpiTile;
use App\Layout\LineChart;
use App\Layout\Measured;
use App\Layout\Note;
use App\Layout\Page;
use App\Layout\Series;
use App\Layout\Table;
use App\Layout\Text;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds a page from the composer's choices, without any AI: the same Page a model would send, so
 * the same resolver, proofs and refusals apply.
 */
final readonly class PageComposer
{
    public function __construct(
        private Catalog $catalog,
        private Facts $facts,
        private Places $places,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Destinations side by side for one month: a bar chart per indicator. A climate normal gives
     * that month of the year; monthly data, its latest such month; annual data, its latest year,
     * titled as a whole year.
     * The latest period is the last one every chosen place has, so the bars compare like with like;
     * each bar's caption says which period it is.
     * A city takes the figure of its country where an indicator has no figure for the city (prices
     * are published by country): the bar is the country's, and the title names the cities it
     * stands for.
     * One page per theme of the catalog, in the order of the themes, titled with the question of
     * the traveller it answers ("How much does it cost?"); a theme without a chart has no page.
     *
     * @return list<Page>
     */
    public function compare(Comparison $comparison): array
    {
        $month = (string) $comparison->month;
        $components = [];
        /** @var array<string, array<string, true>> $cities the cities that took their country's figure, by theme */
        $cities = [];
        foreach ($this->catalog->all() as $entry) {
            [$places, $standIns] = self::places($entry, $comparison->places);
            if ([] === $places || \in_array($entry->id, self::NOT_A_TRIP, true)) {
                continue;
            }
            $period = match ($entry->time->granularity) {
                Granularity::MonthOfYear => 'M'.$month,
                Granularity::Monthly => $this->latest($entry, $places, static fn (string $p): bool => str_ends_with($p, '-'.$month)),
                Granularity::Annual => $this->latest($entry, $places, static fn (): bool => true),
            };
            if (null !== $period) {
                // The title says which kind of period the bars are: annual data is not about the month.
                $locale = $this->translator->getLocale();
                $parameters = ['%title%' => $entry->titleIn($locale), '%month%' => self::monthName($month, $locale)];
                $title = $this->translator->trans(match ($entry->time->granularity) {
                    Granularity::MonthOfYear => '%title%, %month%, average over many years',
                    Granularity::Monthly => '%title%, %month%',
                    Granularity::Annual => '%title%, whole year',
                }, $parameters);
                // Said once per theme (a note first), not in every title.
                foreach ($standIns as $city) {
                    $cities[$entry->theme->value][$city] = true;
                }
                $components[$entry->theme->value][] = new BarChart('bar', $title, array_map(static fn (string $geo) => new Measured('measured', $entry->id, $geo, $period), $places));
                $extremes = $this->extremes($entry, $places, $period);
                if (null !== $extremes) {
                    $components[$entry->theme->value][] = $extremes;
                }
            }
        }

        $pages = [];
        foreach (Theme::cases() as $theme) {
            if (isset($components[$theme->value])) {
                $names = array_map($this->places->name(...), array_map(strval(...), array_keys($cities[$theme->value] ?? [])));
                if ([] !== $names) {
                    $list = 1 === \count($names) ? $names[0] : $this->translator->trans('%list% and %last%', ['%list%' => implode(', ', \array_slice($names, 0, -1)), '%last%' => end($names)]);
                    $sentence = $this->translator->trans(1 === \count($names) ? '%cities% takes the figures of its country here: the source publishes them by country.' : '%cities% take the figures of their country here: the source publishes them by country.', ['%cities%' => $list]);
                    array_unshift($components[$theme->value], new Note('note', [new Text('text', $sentence)]));
                }
                $pages[] = new Page($this->translator->trans($theme->question()), $components[$theme->value]);
            }
        }

        return $pages;
    }

    /**
     * What stands out of a chart, said under it: its highest and its lowest bar, each one the cell
     * itself with its proof. No sentence when the bars are equal, or fewer than two.
     * "Highest" and "lowest" only: whether high is good is the reader's call.
     *
     * @param list<string> $places
     */
    private function extremes(CatalogEntry $entry, array $places, string $period): ?Note
    {
        $values = [];
        foreach ($places as $geo) {
            $value = $this->facts->find($entry->id, $geo, $period)?->value;
            if (null !== $value) {
                $values[] = [$geo, $value];
            }
        }
        if (2 > \count($values)) {
            return null;
        }
        [$high, $low] = [$values[0], $values[0]];
        foreach ($values as $cell) {
            $high = $cell[1] > $high[1] ? $cell : $high;
            $low = $cell[1] < $low[1] ? $cell : $low;
        }
        if ($high[1] === $low[1]) {
            return null;
        }
        $cell = static fn (string $geo): FigureRef => new FigureRef('figure', new Measured('measured', $entry->id, $geo, $period));

        return new Note('note', [
            new Text('text', $this->translator->trans('Highest:').' '),
            $cell($high[0]),
            new Text('text', '. '.$this->translator->trans('Lowest:').' '),
            $cell($low[0]),
            new Text('text', '.'),
        ]);
    }

    /**
     * Totals of a whole country: a city cannot take them as its own. A price level or a rate of the
     * country says something of its cities; its number of inhabitants or its forest does not.
     */
    private const array OF_THE_COUNTRY = ['population', 'forest_area'];
    /** Shipped to show that the page is not about travel: they say nothing of a destination, and stay out of the comparison of destinations. */
    private const array NOT_A_TRIP = ['gdp_per_inhabitant', 'inflation', 'house_prices', 'renewable_share', 'municipal_waste', 'forest_area', 'cars_per_inhabitant', 'population'];

    /**
     * The places of an entry for the chosen ones, in their order: each place it covers, or else the
     * country of a city, once.
     *
     * @param list<string> $chosen
     *
     * @return array{list<string>, list<string>} the places, and the cities that took their country's figure
     */
    private static function places(CatalogEntry $entry, array $chosen): array
    {
        $places = [];
        $standIns = [];
        foreach ($chosen as $geo) {
            $country = Places::country($geo);
            $place = match (true) {
                $entry->covers($geo) => $geo,
                null !== $country && !\in_array($entry->id, self::OF_THE_COUNTRY, true) && $entry->covers($country) => $country,
                default => null,
            };
            if (null === $place) {
                continue;
            }
            // Said even when the country is already a bar: the city is not left out without a word.
            if ($place !== $geo) {
                $standIns[] = $geo;
            }
            if (!\in_array($place, $places, true)) {
                $places[] = $place;
            }
        }

        return [$places, $standIns];
    }

    /**
     * The comparison a first visit shows: three cities in September if the catalog has them
     * (Seville, Lisbon, Split), else three countries (Spain, Portugal, Greece). Only the places the
     * catalog knows; none if fewer than two.
     */
    public function example(): Comparison
    {
        $known = array_merge(...array_values(array_map(static fn (CatalogEntry $entry): array => $entry->geo, $this->catalog->all())));
        $comparison = new Comparison();
        $comparison->month = '09';
        foreach ([['ES004C', 'PT001C', 'HR005C'], ['ES', 'PT', 'EL']] as $places) {
            $places = array_values(array_intersect($places, $known));
            if (2 <= \count($places)) {
                $comparison->places = $places;
                break;
            }
        }

        return $comparison;
    }

    /** "09" is September, "septembre" in French; "Sep", "sept." when short. */
    public static function monthName(string $month, string $locale = 'en', bool $short = false): string
    {
        $format = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, $short ? 'LLL' : 'LLLL');

        return (string) $format->format(new \DateTimeImmutable('2000-'.$month.'-15', new \DateTimeZone('UTC')));
    }

    /**
     * The latest period of an entry that every place has a value for, among those $keep accepts.
     *
     * @param list<string>            $places
     * @param \Closure(string): bool $keep
     */
    private function latest(CatalogEntry $entry, array $places, \Closure $keep): ?string
    {
        foreach (array_reverse(array_filter($entry->time->periods(), $keep)) as $period) {
            foreach ($places as $geo) {
                if (null === $this->facts->find($entry->id, $geo, $period)?->value) {
                    continue 2;
                }
            }

            return $period;
        }

        return null;
    }

    /**
     * A page for a question read without a model: the indicators and the places its words named.
     * One block per indicator, on the period the question gave, or else:
     * - a climate normal without a month: the whole year, a line per place;
     * - a span of years ("since …"): a line per place over it, then the last year side by side;
     * - anything else: the latest period every place has, as /compare shows it.
     * Null when no indicator covers any of the places.
     *
     * @param list<CatalogEntry> $entries
     * @param list<string>       $places
     * @param ?string            $lead    a sentence of the server put first, when the question had two readings
     * @param bool               $ranked  the places were chosen by the server, by their value: the page is titled by its figure, and a chart already sorted has no "highest, lowest" sentence
     */
    public function answer(array $entries, array $places, ?string $month, ?string $from, ?string $to, ?string $lead = null, bool $ranked = false): ?Page
    {
        $locale = $this->translator->getLocale();
        $components = [];
        $standing = [];
        $titled = [];
        foreach ($entries as $entry) {
            [$covered, $standIns] = self::places($entry, $places);
            if ([] === $covered) {
                continue;
            }
            $title = $entry->titleIn($locale);
            $cell = static fn (string $geo, string $time): Measured => new Measured('measured', $entry->id, $geo, $time);
            $periods = $entry->time->periods();
            $block = [];
            if (Granularity::MonthOfYear === $entry->time->granularity && null === $month) {
                $block[] = new LineChart('line', $this->translator->trans('%title%, month by month, average over many years', ['%title%' => $title]), array_map(static fn (string $geo) => new Series($entry->id, $geo, 'M01', 'M12'), $covered));
            } elseif (Granularity::Annual === $entry->time->granularity && null !== $from && \in_array($from, $periods, true)) {
                $last = null !== $to && \in_array($to, $periods, true) ? $to : $this->latest($entry, $covered, static fn (string $p): bool => $p >= $from);
                if (null === $last || $last <= $from) {
                    continue;
                }
                $block[] = new LineChart('line', $this->translator->trans('%title%, over time', ['%title%' => $title]), array_map(static fn (string $geo) => new Series($entry->id, $geo, $from, $last), $covered));
                $block[] = new Table('table', $this->translator->trans('First and last period'), array_merge(...array_map(static fn (string $geo) => [$cell($geo, $from), $cell($geo, $last)], $covered)));
            } else {
                $period = match ($entry->time->granularity) {
                    Granularity::MonthOfYear => 'M'.$month,
                    // The month of the year asked for when the data has it, else the latest such month.
                    Granularity::Monthly => null !== $month && null !== $to && \in_array($to.'-'.$month, $periods, true)
                        ? $to.'-'.$month
                        : $this->latest($entry, $covered, null === $month ? static fn (): bool => true : static fn (string $p): bool => str_ends_with($p, '-'.$month)),
                    Granularity::Annual => null !== $to && \in_array($to, $periods, true) ? $to : $this->latest($entry, $covered, static fn (): bool => true),
                };
                if (null === $period) {
                    continue;
                }
                $block[] = 1 === \count($covered) ? new KpiTile('kpi', $title, $cell($covered[0], $period)) : new BarChart('bar', $title, array_map(static fn (string $geo) => $cell($geo, $period), $covered));
                $extremes = $ranked ? null : $this->extremes($entry, $covered, $period);
                if (null !== $extremes) {
                    $block[] = $extremes;
                }
            }
            array_push($components, ...$block);
            foreach ($standIns as $city) {
                $standing[$city] = true;
            }
            foreach ($places as $geo) {
                if (\in_array($geo, $covered, true) || \in_array($geo, $standIns, true)) {
                    $titled[$geo] = true;
                }
            }
        }
        if ([] === $components) {
            return null;
        }
        // Said once, first: a city that took the figure of its country.
        $names = array_map($this->places->name(...), array_map(strval(...), array_keys($standing)));
        if ([] !== $names) {
            $list = 1 === \count($names) ? $names[0] : $this->translator->trans('%list% and %last%', ['%list%' => implode(', ', \array_slice($names, 0, -1)), '%last%' => end($names)]);
            array_unshift($components, new Note('note', [new Text('text', $this->translator->trans(1 === \count($names) ? '%cities% takes the figures of its country here: the source publishes them by country.' : '%cities% take the figures of their country here: the source publishes them by country.', ['%cities%' => $list]))]));
        }
        // Said before anything else: the question had more than one reading.
        if (null !== $lead) {
            array_unshift($components, new Note('note', [new Text('text', $lead)]));
        }
        $shown = array_map($this->places->name(...), array_values(array_filter($places, static fn (string $geo): bool => isset($titled[$geo]))));

        if ($ranked) {
            return new Page($entries[0]->titleIn($locale), $components);
        }

        return new Page(1 === \count($shown) ? $shown[0] : $this->translator->trans('%list% and %last%', ['%list%' => implode(', ', \array_slice($shown, 0, -1)), '%last%' => end($shown)]), $components);
    }

    /**
     * The places of an indicator with the highest values (or the lowest), for a question that names
     * none ("which country is cheapest?"): countries and cities only, no group of countries. On the
     * month or the year asked for when the data has it, else on the latest period that most places
     * have. None for a climate normal without a month: there is no one period to rank on.
     *
     * @return list<string> place codes, best first
     */
    public function ranked(CatalogEntry $entry, ?string $month, ?string $year, bool $lowest, int $limit): array
    {
        $places = array_values(array_filter($entry->geo, static fn (string $geo): bool => Places::isCity($geo) || Places::isCountry($geo) || Places::isOwn($geo)));
        $periods = $entry->time->periods();
        $candidates = match ($entry->time->granularity) {
            Granularity::MonthOfYear => null === $month ? [] : ['M'.$month],
            Granularity::Monthly => null !== $month && null !== $year && \in_array($year.'-'.$month, $periods, true)
                ? [$year.'-'.$month]
                : array_reverse(array_values(array_filter($periods, static fn (string $p): bool => null === $month || str_ends_with($p, '-'.$month)))),
            Granularity::Annual => null !== $year && \in_array($year, $periods, true) ? [$year] : array_reverse($periods),
        };
        foreach ($candidates as $period) {
            $values = [];
            foreach ($places as $geo) {
                $value = $this->facts->find($entry->id, $geo, $period)?->value;
                if (null !== $value) {
                    $values[$geo] = $value;
                }
            }
            // A period most places have: the latest year is often published for a few countries only.
            if (\count($values) >= max(2, (int) ceil(\count($places) / 2))) {
                $lowest ? asort($values) : arsort($values);

                return \array_slice(array_map(strval(...), array_keys($values)), 0, $limit);
            }
        }

        return [];
    }

    public function page(Composition $composition): Page
    {
        $entry = $this->catalog->get((string) $composition->dataset)
            ?? throw new \InvalidArgumentException(\sprintf('Unknown dataset "%s".', $composition->dataset));
        [$from, $to] = [(string) $composition->from, (string) $composition->to];
        $cell = static fn (string $geo, string $time): Measured => new Measured('measured', $entry->id, $geo, $time);
        $title = $entry->titleIn($this->translator->getLocale());

        // The last period first: one tile for one place, bars to compare several.
        $components = [1 === \count($composition->places)
            ? new KpiTile('kpi', $title, $cell($composition->places[0], $to))
            // Under the page's title, the bars say what they compare, not the title again.
            : new BarChart('bar', $this->translator->trans('By place'), array_map(static fn (string $geo) => $cell($geo, $to), $composition->places)),
        ];

        if ($from !== $to) {
            $components[] = new LineChart('line', $this->translator->trans('Over time'), array_map(
                static fn (string $geo) => new Series($entry->id, $geo, $from, $to),
                $composition->places,
            ));
            $components[] = new Table('table', $this->translator->trans('First and last period'), array_merge(...array_map(
                static fn (string $geo) => [$cell($geo, $from), $cell($geo, $to)],
                $composition->places,
            )));
        }

        return new Page($title, $components);
    }
}
