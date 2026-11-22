<?php

declare(strict_types=1);

namespace App\Import;

use App\Catalog\Catalog;
use App\Catalog\CatalogEntry;
use App\Catalog\CatalogLoader;
use App\Catalog\InvalidCatalog;
use App\Catalog\Places;
use App\Layout\Constraint\Render;
use Psr\Clock\ClockInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Turns a proposal into a catalog entry and its cells (the extended mode).
 *
 * The proposal names codes and writes words; the server checks every code against the dataset
 * (only the place and the period stay free), reads every value from Eurostat itself, keeps the
 * places of the shipped catalog that have a value, and stores the cells with the date of the import.
 * A proposal that breaks a rule is refused with every reason, and nothing is kept.
 */
final readonly class Importer
{
    public const int MAX_CELLS = 50_000;
    /** Words of a unit the server writes itself, from the unit code: never left to free text. */
    private const array SCALE_WORDS = ['thousand', 'thousands', 'million', 'millions', 'billion', 'billions', 'hundred', 'hundreds', 'inhabitant', 'inhabitants', 'k', 'm', 'b', 'g', 't', 'ths', 'thsd', 'thou', 'mil', 'mill', 'mio', 'mn', 'bn', 'bil', 'mrd', 'tn', 'trn', 'kilo', 'mega', 'giga', 'tera', 'meur', 'keur', 'beur', 'mnac', 'pct', 'percent', 'per'];
    private const string NOT_TAKEN = 'Go Figure does not add this dataset: it takes the economy, businesses, agriculture, energy, the environment, transport, tourism, prices and culture, and no subject about people\'s lives (health, migration, justice, living conditions).';
    /** A scale in the label of a code: thousands, millions, per head. */
    /**
     * A scale said in words or in a short form ("thousand", "Ths", "MEUR"), or any number at all:
     * "(1 000 hl)", "x1000", "10³". For a dataset without a unit code, the server could not write
     * the unit right and would show a value a thousand times off. It refuses some harmless choices
     * too ("From 10 to 49 persons"): on the side of caution.
     */
    private const string SCALED = '/\\b(hundreds?|thousands?|thsd?|ths|millions?|mio|mn|meur|keur|billions?|bn|trillions?|per (inhabitant|capita|head|person|hectare|km|day|year))\\b|\\p{N}/iu';
    /** The unit code of a dataset that has no dimension "unit": no scale can be read from it. */
    public const string NO_UNIT = 'NONE';
    /** Small words a unit may use besides the words of the dataset ("of the", "per"…). */
    private const array SMALL_WORDS = ['of', 'the', 'in', 'at', 'by', 'a', 'an', 'and', 'or', 'to', 'from', 'on'];
    private const string LICENCE = 'Eurostat, reuse authorised with source acknowledgement';
    /** Eurostat writes a month 2024-07; a quarter (2024-Q3) or a day is not a period of the catalog. */
    private const array PERIODS = ['A' => ['annual', '/^\d{4}$/D'], 'M' => ['monthly', '/^\d{4}-(0[1-9]|1[0-2])$/D']];

    public function __construct(
        private EurostatApi $eurostat,
        private ImportStore $store,
        private Catalog $catalog,
        private CatalogLoader $loader,
        private ValidatorInterface $validator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Why a proposal cannot be imported: its texts, then its codes against the dataset.
     *
     * @return list<array{string, array<string, string>}> message templates and their parameters
     */
    public function problems(Proposal $proposal, DatasetStructure $structure): array
    {
        $problems = [];
        foreach ($this->validator->validate($proposal, groups: [Render::GROUP]) as $violation) {
            $problems[] = ['%field%: %message%', ['%field%' => self::field($violation->getPropertyPath()), '%message%' => (string) $violation->getMessage()]];
        }
        if ($proposal->code !== $structure->code) {
            $problems[] = ['The proposal is for %code%, not for this dataset.', ['%code%' => $proposal->code]];
        }
        if (!EurostatApi::allowed($structure->code, $structure->label)) {
            $problems[] = [self::NOT_TAKEN, []];
        }
        // A dataset without a dimension "unit" says its unit in its title or in the indicator chosen
        // ("Price level indices"): the label is then made of those words only, as for any import.
        if (null === $structure->dimension('unit') && [] === self::unitWords($proposal->unitLabel)) {
            $problems[] = ['unit: this dataset has no unit of its own, so write one with the words of its title or of the codes chosen.', []];
        }
        foreach (self::unitWords($proposal->unitLabel) as $word) {
            if (\in_array($word, self::SCALE_WORDS, true)) {
                $problems[] = ['unit: "%word%" is a scale: write the unit without it, the server adds the scale of the unit code.', ['%word%' => $word]];
            } elseif (!\in_array($word, self::SMALL_WORDS, true) && !\in_array($word, self::datasetWords($structure, $proposal->frozen()), true)) {
                $problems[] = ['unit: use the words of the dataset itself (its title and the codes chosen); "%word%" is not one.', ['%word%' => $word]];
            }
        }

        // Rule 5 on what a visitor or a model wrote, as on the title of the dataset.
        if (EurostatApi::sensitive(implode(' ', [$proposal->title, $proposal->scope, $proposal->unitLabel, ...$proposal->pitfalls]))) {
            $problems[] = [self::NOT_TAKEN, []];
        }

        $chosen = [];
        foreach ($proposal->dimensions as $choice) {
            $dimension = $structure->dimension($choice->dimension);
            $label = (string) ($dimension?->codes[$choice->code] ?? '');
            // Rule 5 on the code chosen: a dataset of an accepted family can still break down by a
            // subject about people ("Deaths", "Foreign-born").
            if (EurostatApi::sensitive($label)) {
                $problems[] = [self::NOT_TAKEN, []];
            }
            // Without a unit code, nothing tells the server the scale: a code that carries one
            // ("in million euro", "per inhabitant") would be shown as a plain figure.
            if (null === $structure->dimension('unit') && 1 === preg_match(self::SCALED, $label)) {
                $problems[] = ['%code% carries a scale ("%label%") and this dataset has no unit code to check it: Go Figure cannot add this choice.', ['%code%' => $choice->code, '%label%' => $label]];
            }
            if (null === $dimension) {
                $problems[] = ['The dataset has no dimension %dimension% to fix (geo and time stay free).', ['%dimension%' => $choice->dimension]];
            } elseif (isset($chosen[$choice->dimension])) {
                $problems[] = ['The dimension %dimension% is fixed twice.', ['%dimension%' => $choice->dimension]];
            } elseif (!\array_key_exists($choice->code, $dimension->codes)) {
                $problems[] = ['%code% is not a code of the dimension %dimension%.', ['%code%' => $choice->code, '%dimension%' => $choice->dimension]];
            } elseif (1 !== preg_match(EurostatApi::DIMENSION, $choice->dimension) || 1 !== preg_match(EurostatApi::CATEGORY, $choice->code)) {
                $problems[] = ['Go Figure cannot ask Eurostat for %dimension%=%code%.', ['%code%' => $choice->code, '%dimension%' => $choice->dimension]];
            }
            $chosen[$choice->dimension] = true;
        }
        foreach ($structure->dimensions as $dimension) {
            if (!isset($chosen[$dimension->id])) {
                $problems[] = ['Choose one code for the dimension %dimension% (%label%): only the place and the period may vary.', ['%dimension%' => $dimension->id, '%label%' => $dimension->label]];
            }
        }
        $frequency = $proposal->frozen()['freq'] ?? null;
        if (null !== $frequency && !isset(self::PERIODS[$frequency])) {
            $problems[] = ['Only yearly or monthly figures can be imported, not the frequency %code%.', ['%code%' => $frequency]];
        }

        return $problems;
    }

    /**
     * Downloads the cells of the proposal and stores them with its entry.
     *
     * @throws ImportRefused       with every reason, nothing kept
     * @throws EurostatUnavailable nothing kept
     */
    public function import(Proposal $proposal, DatasetStructure $structure): CatalogEntry
    {
        $problems = $this->problems($proposal, $structure);
        if ([] !== $problems) {
            throw new ImportRefused($problems);
        }
        $frozen = $proposal->frozen();
        $id = self::id($proposal->code, $frozen);
        // Never replaced: its figures may already be on pages, their proofs carry the date of the import.
        if ($this->store->has($id)) {
            $existing = $this->catalog->get($id);
            throw new ImportRefused([['This choice is already imported, as "%title%".', ['%title%' => null === $existing ? $id : $existing->title]]]);
        }
        if ($this->store->count() >= ImportStore::MAX_ENTRIES) {
            throw new ImportRefused([['This Go Figure keeps at most %max% imported datasets.', ['%max%' => (string) ImportStore::MAX_ENTRIES]]]);
        }

        $places = $this->places();
        $data = $this->eurostat->data($proposal->code, $frozen, $places);
        $cells = JsonStat::cells($data);
        if (\count($cells) > self::MAX_CELLS) {
            throw new ImportRefused([['Too many cells for one import: fix more dimensions.', []]]);
        }

        $frequency = $frozen['freq'] ?? self::frequency($cells);
        if (!isset(self::PERIODS[$frequency])) {
            throw new ImportRefused([['Only yearly or monthly figures can be imported.', []]]);
        }
        [$granularity, $pattern] = self::PERIODS[$frequency];
        $today = $this->clock->now()->format('Y-m-d');
        $updated = substr((string) ($data['updated'] ?? ''), 0, 10) ?: $today;
        // No unit code: nothing to read a scale from, the label says it all (self::NO_UNIT).
        $unit = $frozen['unit'] ?? self::NO_UNIT;
        $rows = [];
        $covered = [];
        $periods = [];
        foreach ($cells as [$geo, $period, $value, $flag]) {
            if (!\in_array($geo, $places, true) || 1 !== preg_match($pattern, $period)) {
                continue;
            }
            $rows[] = [$id, $geo, $period, $unit, $value, $flag, $updated, $today];
            if (null !== $value) {
                $covered[$geo] = true;
                $periods[] = $period;
            }
        }
        if ([] === $periods) {
            throw new ImportRefused([['Eurostat has no value for these choices in the places of Go Figure.', []]]);
        }
        // A place with no value at all is left out: the page says the dataset does not cover it.
        $rows = array_values(array_filter($rows, static fn (array $row): bool => isset($covered[$row[1]])));

        $entry = [
            'id' => $id,
            'title' => ['en' => trim($proposal->title)],
            'source' => [
                'provider' => 'eurostat',
                'dataset' => $proposal->code,
                'url' => 'https://ec.europa.eu/eurostat/databrowser/view/'.$proposal->code.'/default/table',
                'licence' => self::LICENCE,
            ],
            'dimensions' => $frozen,
            'geo' => array_values(array_filter($places, static fn (string $geo): bool => isset($covered[$geo]))),
            'time' => ['granularity' => $granularity, 'from' => min($periods), 'to' => max($periods)],
            'unit' => ['code' => $unit, 'label' => UnitLabel::of($unit, $proposal->unitLabel)],
            'scope' => ['en' => trim($proposal->scope)],
            'pitfalls' => [
                ...array_map(trim(...), $proposal->pitfalls),
                \sprintf('Imported from Eurostat on %s on this machine: its title, scope, unit and pitfalls were written by a visitor or a model, not by Go Figure. The figures are Eurostat\'s.', $today),
                ...(self::NO_UNIT === $unit ? ['Eurostat gives this dataset no unit code: the unit shown uses the words of its title and of the codes chosen.'] : []),
            ],
            'theme' => $proposal->theme->value,
            // No share: it needs a part and its whole, which an import cannot tell. No percent of a percent.
            'operations' => str_starts_with($unit, 'PC') ? ['change', 'rank'] : ['change', 'change_pct', 'rank'],
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

    /**
     * The id of an imported entry: its dataset and a fingerprint of its frozen dimensions. The same
     * choice gets the same id, and is never imported twice.
     *
     * @param array<string, string> $frozen
     */
    public static function id(string $code, array $frozen): string
    {
        ksort($frozen);

        return ImportStore::PREFIX.substr($code, 0, 28).'_'.substr(hash('sha256', $code.'|'.json_encode($frozen)), 0, 6);
    }

    /**
     * @return list<string> the words of a unit label, in lower case
     */
    private static function unitWords(string $label): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower($label)) ?: [], static fn (string $w): bool => '' !== $w));
    }

    /**
     * The words a unit may use: the dataset's title and the labels of the codes chosen. Not the
     * other codes: "rate of change" must not label an index of the same dataset.
     *
     * @param array<string, string> $frozen
     *
     * @return list<string>
     */
    private static function datasetWords(DatasetStructure $structure, array $frozen): array
    {
        $text = $structure->label;
        foreach ($structure->dimensions as $dimension) {
            $text .= ' '.($dimension->codes[$frozen[$dimension->id] ?? ''] ?? '');
        }

        return self::unitWords($text);
    }

    /**
     * The countries of the shipped catalog (and the EU aggregate): an import never adds a place the
     * pages do not know.
     *
     * @return list<string>
     */
    private function places(): array
    {
        $places = [];
        foreach ($this->catalog->shipped() as $entry) {
            foreach ($entry->geo as $geo) {
                if (!Places::isCity($geo)) {
                    $places[$geo] = true;
                }
            }
        }

        return array_keys($places);
    }

    /**
     * Annual or monthly, from the periods themselves, when the dataset has no freq dimension.
     *
     * @param list<array{string, string, ?float, string}> $cells
     */
    private static function frequency(array $cells): string
    {
        $period = $cells[0][1] ?? '';

        return match (true) {
            1 === preg_match(self::PERIODS['A'][1], $period) => 'A',
            1 === preg_match(self::PERIODS['M'][1], $period) => 'M',
            default => '?',
        };
    }

    private static function field(string $path): string
    {
        return match (true) {
            str_starts_with($path, 'unitLabel') => 'unit',
            str_starts_with($path, 'pitfalls') => 'pitfalls',
            default => $path,
        };
    }
}
