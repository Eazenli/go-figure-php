<?php

declare(strict_types=1);

namespace App\Import;

use App\Catalog\Catalog;
use App\Catalog\CatalogEntry;
use App\Catalog\CatalogLoader;
use App\Catalog\InvalidCatalog;
use App\Catalog\Theme;
use App\Layout\Constraint\FreeText;
use Symfony\Component\Clock\ClockInterface;

/**
 * Adds one indicator of the World Bank to the catalog of this machine: every value by country and
 * year, read from the World Bank by the server. Nothing here is written by a visitor or a model:
 * the title and the unit are derived by the server from the name the World Bank gives the indicator
 * ("GDP (current US$)": its title, then its unit), the scope is the World Bank's note.
 */
final readonly class WorldBankImporter
{
    /**
     * CC BY 4.0 is the licence the World Bank gives its own datasets by default; an indicator it
     * takes from a third party can carry other terms, which its page (the source link) names.
     */
    private const string LICENCE = 'World Bank, CC BY 4.0 unless the page of the indicator says otherwise';
    /** The codes of Eurostat for the two countries it does not write as ISO 3166 does. */
    private const array PLACES = ['GR' => 'EL', 'GB' => 'UK'];
    /** What an indicator counts, when its name does not say: written here, by its id. */
    private const array UNITS = ['SP.POP.TOTL' => 'people'];

    public function __construct(
        private WorldBankApi $worldBank,
        private ImportStore $store,
        private Catalog $catalog,
        private CatalogLoader $loader,
        private ClockInterface $clock,
    ) {
    }

    public static function id(string $indicator): string
    {
        return substr(ImportStore::PREFIX.'wb_'.strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $indicator)), 0, 40);
    }

    /**
     * The title and the unit of an indicator, from its name: "Forest area (% of land area)" is
     * "Forest area", in percent "of land area"; "International tourism, number of arrivals" counts
     * "arrivals"; "Population, total" has no unit of its own and counts its first word.
     *
     * @return array{title: string, code: string, label: string}
     */
    public static function words(string $name): array
    {
        $title = $name;
        $unit = '';
        if (1 === preg_match('/^(.+?)\s*\(([^()]+)\)\s*$/', $name, $m)) {
            [$title, $unit] = [trim($m[1]), trim($m[2])];
        }
        // "International tourism, number of arrivals": the name says what it counts, in its own words.
        if ('' === $unit && 1 === preg_match('/^(.+?), number of ([\p{L} ]+)$/Du', $name, $m)) {
            return ['title' => trim($m[1]), 'code' => Importer::NO_UNIT, 'label' => trim($m[2])];
        }
        if ('' === $unit) {
            // No unit in the name: the import refuses it, but for the indicators it knows (self::UNITS).
            return ['title' => $title, 'code' => Importer::NO_UNIT, 'label' => ''];
        }
        if (str_contains($unit, '%')) {
            // The number carries the sign; the label says of what ("of GDP", "annual").
            $label = trim((string) preg_replace('/\s+/', ' ', str_replace('%', '', $unit)));

            return ['title' => $title, 'code' => 'PC', 'label' => '' === $label ? '%' : $label];
        }

        return ['title' => $title, 'code' => Importer::NO_UNIT, 'label' => str_replace(['US$', 'international $'], ['US dollars', 'international dollars'], $unit)];
    }

    /**
     * @throws ImportRefused        with every reason, nothing kept
     * @throws DatasetNotFound
     * @throws DatasetNotAllowed
     * @throws WorldBankUnavailable nothing kept
     */
    public function import(string $indicator, Theme $theme = Theme::Other): CatalogEntry
    {
        $meta = $this->worldBank->indicator($indicator);
        $words = self::words($meta['name']);
        // A title is shown on every page, where every number is a proven figure: the rule of the page.
        if (null !== FreeText::problem($words['title'])) {
            throw new ImportRefused([['The name of this indicator holds a number: Go Figure cannot show it as a title.', []]]);
        }
        if ('' === $words['label']) {
            $words['label'] = self::UNITS[$indicator] ?? '';
        }
        // A unit is shown after every figure: the rule of the page applies to it too ("constant 2015
        // US$", "2010 = 100" and "per 100 people" hold numbers no proof stands behind).
        if ('' === $words['label'] || ('%' !== $words['label'] && null !== FreeText::problem($words['label']))) {
            throw new ImportRefused([['The name of this indicator does not give a unit Go Figure can show without a number: it cannot be added.', []]]);
        }
        // In the currency of each country: figures that cannot stand side by side.
        if (1 === preg_match('/\bLCU\b/', $words['label'])) {
            throw new ImportRefused([['This indicator is in the currency of each country: its figures cannot be compared, Go Figure does not add it.', []]]);
        }
        $id = self::id($indicator);
        if ($this->store->count() >= ImportStore::MAX_ENTRIES) {
            throw new ImportRefused([['This Go Figure keeps at most %max% imported datasets.', ['%max%' => (string) ImportStore::MAX_ENTRIES]]]);
        }
        if ($this->store->has($id)) {
            throw new ImportRefused([['This choice is already imported, as "%title%".', ['%title%' => $this->catalog->get($id)->title ?? $id]]]);
        }

        $data = $this->worldBank->data($indicator);
        $today = $this->clock->now()->format('Y-m-d');
        $updated = '' !== $data['updated'] ? $data['updated'] : $today;
        $rows = [];
        $covered = [];
        $periods = [];
        foreach ($data['cells'] as [$country, $year, $value]) {
            $geo = self::PLACES[$country] ?? $country;
            $rows[] = [$id, $geo, $year, $words['code'], $value, '', $updated, $today];
            $covered[$geo] = true;
            $periods[] = $year;
        }
        if ([] === $rows) {
            throw new ImportRefused([['The World Bank has no value for this indicator.', []]]);
        }
        if (\count($rows) > Importer::MAX_CELLS) {
            throw new ImportRefused([['Too many cells for one import: fix more dimensions.', []]]);
        }
        $places = array_keys($covered);
        sort($places);

        $entry = [
            'id' => $id,
            'title' => ['en' => $words['title']],
            'source' => [
                'provider' => 'world_bank',
                'dataset' => $indicator,
                'url' => 'https://data.worldbank.org/indicator/'.$indicator,
                'licence' => self::LICENCE,
            ],
            'geo' => $places,
            'time' => ['granularity' => 'annual', 'from' => min($periods), 'to' => max($periods)],
            'unit' => ['code' => $words['code'], 'label' => $words['label']],
            'scope' => ['en' => self::scope($meta['note'], $meta['name'])],
            'pitfalls' => [
                \sprintf('Imported from the World Bank (World Development Indicators) on %s on this machine: its title and unit come from the name the World Bank gives the indicator, its scope from the World Bank\'s note. Values from the year 2000 on.', $today),
            ],
            'theme' => $theme->value,
            // No share: it needs a part and its whole, which an import cannot tell. No percent of a percent.
            'operations' => 'PC' === $words['code'] ? ['change', 'rank'] : ['change', 'change_pct', 'rank'],
        ];
        try {
            $imported = $this->loader->fromArray($entry);
        } catch (InvalidCatalog $e) {
            throw new ImportRefused(array_map(static fn (string $error): array => ['%error%', ['%error%' => $error]], $e->errors));
        }
        if (!$this->store->add($entry, $rows, ImportStore::MAX_ENTRIES)) {
            throw new ImportRefused([['This choice is already imported, or this Go Figure already keeps %max% imported datasets.', ['%max%' => (string) ImportStore::MAX_ENTRIES]]]);
        }
        $this->catalog->reset();

        return $imported;
    }

    /** What the indicator measures, in the World Bank's words: its first sentences, kept short. */
    private static function scope(string $note, string $name): string
    {
        $note = trim((string) preg_replace('/\s+/', ' ', $note));
        if ('' === $note) {
            return $name.'.';
        }
        $scope = '';
        foreach (preg_split('/(?<=[.!?])\s+/', $note) ?: [] as $sentence) {
            if ('' !== $scope && mb_strlen($scope.' '.$sentence) > 400) {
                break;
            }
            $scope = trim($scope.' '.$sentence);
        }

        return mb_strlen($scope) > 600 ? mb_substr($scope, 0, 597).'…' : $scope;
    }
}
