<?php

declare(strict_types=1);

namespace App\Import;

use App\Catalog\Catalog;
use App\Catalog\CatalogEntry;
use App\Catalog\CatalogLoader;
use App\Catalog\InvalidCatalog;
use App\Catalog\Places;
use App\Layout\Constraint\FreeText;

/**
 * A file someone deposits (CSV) becomes datasets the page can question: one column of places or
 * categories, one of periods (years, or years and months), and one dataset per column of numbers.
 * The values are the file's; the server reads them as written and proves them like any other:
 * the proof names the file, the column, the day it was deposited and the fingerprint of the file.
 *
 * Kept on this machine, in the store of the imports. A model is never sent a value: like for any
 * dataset, it sees a title, a unit, the places and the periods.
 */
final readonly class FileImporter
{
    public const string PROVIDER = 'your_file';
    public const int MAX_BYTES = 256 * 1024;
    public const int MAX_ROWS = 5000;
    public const int MAX_PLACES = 200;
    public const int MAX_COLUMNS = 6;
    /** A proof needs an address; a file has none to open: this one is never shown as a link. */
    private const string NO_ADDRESS = 'https://go-figure.invalid/your-file';
    /** Headers that say a row is a person: such a file is not taken. Not a proof that it holds none. */
    private const string PERSONAL = '/\b(e-?mail|courriel|phone|t[ée]l[ée]phone|mobile|first ?name|last ?name|surname|pr[ée]nom|nom de famille|birth|naissance|date of birth|ssn|social security|s[ée]curit[ée] sociale|iban|passport|passeport|patient|employee|salari[ée]|address|adresse)\b/iu';

    public function __construct(
        private CatalogLoader $loader,
        private ImportStore $store,
        private Catalog $catalog,
    ) {
    }

    /**
     * What the file holds, before anything is kept: its columns, and which one is what.
     *
     * @return array{headers: list<string>, place: int, period: int, values: list<int>, granularity: string, rows: int, places: list<string>, periods: list<string>, sample: list<list<string>>}
     *
     * @throws ImportRefused when the file is not one the page can question
     */
    public function read(string $csv): array
    {
        if ('' === trim($csv)) {
            throw self::refused('This file is empty.');
        }
        if (\strlen($csv) > self::MAX_BYTES) {
            throw self::refused('This file is larger than %kb% KB.', ['%kb%' => (string) (self::MAX_BYTES / 1024)]);
        }
        if (!mb_check_encoding($csv, 'UTF-8')) {
            throw self::refused('This file is not UTF-8 text: save it as CSV UTF-8.');
        }
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $first = strtok($csv, "\r\n") ?: '';
        $delimiter = [';' => substr_count($first, ';'), "\t" => substr_count($first, "\t"), ',' => substr_count($first, ',')];
        arsort($delimiter);
        $delimiter = (string) array_key_first($delimiter);
        $lines = array_values(array_filter(array_map(static fn (string $line): array => array_map(static fn (?string $cell): string => trim((string) $cell), str_getcsv($line, $delimiter, '"', '')), preg_split('/\r\n|\r|\n/', $csv) ?: []), static fn (array $cells): bool => [] !== array_filter($cells, static fn (string $cell): bool => '' !== $cell)));
        if (\count($lines) < 2) {
            throw self::refused('A file needs a line of column names, then at least one line of values.');
        }
        if (\count($lines) - 1 > self::MAX_ROWS) {
            throw self::refused('This file has more than %max% lines.', ['%max%' => (string) self::MAX_ROWS]);
        }
        $headers = array_shift($lines);
        $width = \count($headers);
        if ($width < 3 || $width !== \count(array_unique($headers)) || [] !== array_filter($headers, static fn (string $header): bool => '' === $header)) {
            throw self::refused('A file needs at least three named columns, each with its own name: places, periods, and one of numbers.');
        }
        if (1 === preg_match(self::PERSONAL, implode(' | ', $headers))) {
            throw self::refused('A column of this file names a person (a name, an address, a date of birth…): Go Figure takes figures about places, products or periods, not about people.');
        }
        foreach ($lines as $n => $cells) {
            if (\count($cells) !== $width) {
                throw self::refused('Line %line% does not have as many cells as the first one.', ['%line%' => (string) ($n + 2)]);
            }
        }

        $column = static fn (int $i): array => array_column($lines, $i);
        $period = $granularity = null;
        $numbers = [];
        foreach (array_keys($headers) as $i) {
            $cells = $column($i);
            if (null === $period && [] === array_filter($cells, static fn (string $cell): bool => 1 !== preg_match('/^(19|20)\d{2}$/D', $cell))) {
                [$period, $granularity] = [$i, 'annual'];
            } elseif (null === $period && [] === array_filter($cells, static fn (string $cell): bool => 1 !== preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/D', $cell))) {
                [$period, $granularity] = [$i, 'monthly'];
            } elseif ([] === array_filter($cells, static fn (string $cell): bool => '' !== $cell && null === self::number($cell)) && [] !== array_filter($cells, static fn (string $cell): bool => '' !== $cell)) {
                $numbers[] = $i;
            }
        }
        if (null === $period || null === $granularity) {
            throw self::refused('No column of periods: one column must hold years (2024) or months (2024-03), and nothing else.');
        }
        $place = null;
        foreach (array_keys($headers) as $i) {
            if ($i !== $period && !\in_array($i, $numbers, true)) {
                $place = $i;
                break;
            }
        }
        if (null === $place) {
            throw self::refused('No column of places: one column must name what each line is about (a place, a product, a category).');
        }
        if ([] === $numbers) {
            throw self::refused('No column of numbers.');
        }
        $places = array_values(array_unique($column($place)));
        foreach ($places as $name) {
            if (1 !== preg_match(Places::OWN_NAME, $name)) {
                throw self::refused('"%name%" cannot name a place: letters, digits and a few signs, forty characters at most.', ['%name%' => mb_substr((string) preg_replace('/[^\p{L}\p{N} .-]+/u', ' ', $name), 0, 40)]);
            }
            if (str_contains($name, '@')) {
                throw self::refused('A column of this file names a person (a name, an address, a date of birth…): Go Figure takes figures about places, products or periods, not about people.');
            }
        }
        if (\count($places) > self::MAX_PLACES) {
            throw self::refused('This file names more than %max% places: with so many, a line is likely a person or a transaction, not a place.', ['%max%' => (string) self::MAX_PLACES]);
        }
        $seen = [];
        foreach ($lines as $n => $cells) {
            $key = $cells[$place]."\0".$cells[$period];
            if (isset($seen[$key])) {
                throw self::refused('Line %line% gives a second value for %name% in %period%: one line per place and period.', ['%line%' => (string) ($n + 2), '%name%' => $cells[$place], '%period%' => $cells[$period]]);
            }
            $seen[$key] = true;
        }
        $periods = array_values(array_unique($column($period)));
        sort($periods);

        return [
            'headers' => $headers,
            'place' => $place,
            'period' => $period,
            'values' => \array_slice($numbers, 0, self::MAX_COLUMNS),
            'granularity' => $granularity,
            'rows' => \count($lines),
            'places' => $places,
            'periods' => $periods,
            'sample' => \array_slice($lines, 0, 5),
        ];
    }

    /**
     * Keeps one dataset per column of numbers the depositor named.
     *
     * @param array<int, array{title: string, unit: string}> $named by column: its title and its unit, in the depositor's words
     *
     * @return list<CatalogEntry>
     *
     * @throws ImportRefused
     */
    public function import(string $csv, string $fileName, array $named): array
    {
        $file = $this->read($csv);
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $delimiter = [';' => 0, "\t" => 0, ',' => 0];
        $first = strtok($csv, "\r\n") ?: '';
        foreach (array_keys($delimiter) as $sign) {
            $delimiter[$sign] = substr_count($first, $sign);
        }
        arsort($delimiter);
        $lines = array_values(array_filter(array_map(static fn (string $line): array => array_map(static fn (?string $cell): string => trim((string) $cell), str_getcsv($line, (string) array_key_first($delimiter), '"', '')), preg_split('/\r\n|\r|\n/', $csv) ?: []), static fn (array $cells): bool => [] !== array_filter($cells, static fn (string $cell): bool => '' !== $cell)));
        array_shift($lines);

        $name = mb_substr(trim((string) preg_replace('/[^\p{L}\p{N} ._-]+/u', ' ', basename($fileName))), 0, 60) ?: 'file';
        $fingerprint = hash('sha256', $csv);
        $today = date('Y-m-d');
        $codes = [];
        foreach ($file['places'] as $place) {
            $codes[$place] = Places::ownCode($place);
        }
        $problems = [];
        $entries = [];
        foreach ($file['values'] as $column) {
            $title = trim($named[$column]['title'] ?? '');
            $unit = trim($named[$column]['unit'] ?? '');
            if ('' === $title) {
                continue; // a column the depositor did not name is not kept
            }
            foreach (['title' => $title, 'unit' => $unit] as $what => $text) {
                if ('' !== $text && null !== FreeText::problem($text)) {
                    $problems[] = ['The %what% of "%column%" holds a number or a sign: words only, the figures come from the file.', ['%what%' => $what, '%column%' => $file['headers'][$column]]];
                }
            }
            if (mb_strlen($title) > 80 || mb_strlen($unit) > 40) {
                $problems[] = ['The title of "%column%" or its unit is too long.', ['%column%' => $file['headers'][$column]]];
            }
            $rows = [];
            $covered = [];
            foreach ($lines as $cells) {
                $value = self::number($cells[$column]);
                if (null === $value) {
                    continue; // an empty cell: the page will say "Missing"
                }
                $geo = $codes[$cells[$file['place']]];
                $covered[$geo] = $cells[$file['place']];
                $rows[] = [null, $geo, $cells[$file['period']], Importer::NO_UNIT, $value, '', $today, $today];
            }
            if ([] === $rows) {
                continue;
            }
            $id = ImportStore::PREFIX.'file_'.substr(hash('sha256', $fingerprint.'|'.$file['headers'][$column]), 0, 12);
            $entries[] = [[
                'id' => $id,
                'title' => ['en' => $title],
                'source' => ['provider' => self::PROVIDER, 'dataset' => $name, 'url' => self::NO_ADDRESS, 'licence' => 'A file deposited on this machine: its figures are the depositor\'s own.'],
                'dimensions' => ['file' => $name, 'column' => mb_substr((string) preg_replace('/[^\p{L}\p{N} ._-]+/u', ' ', $file['headers'][$column]), 0, 60), 'sha256' => $fingerprint],
                'geo' => array_keys($covered),
                'places' => $covered,
                'time' => ['granularity' => $file['granularity'], 'from' => $file['periods'][0] ?? '', 'to' => $file['periods'][\count($file['periods']) - 1] ?? ''],
                'unit' => ['code' => Importer::NO_UNIT, 'label' => '' === $unit ? 'in the unit of the file' : $unit],
                'scope' => ['en' => 'A column of a file deposited on this machine. Go Figure reads its values as they are written, and knows nothing of how they were measured.'],
                'pitfalls' => [
                    'Deposited on this machine: its title and unit were written by the depositor. The figures are those of the file.',
                    // For a model: a text holds no digit, and such a name would have it refused.
                    ...([] === preg_grep('/\p{N}/u', $covered) ? [] : ['Some of its places have a digit in their name: never write such a name in a text or a title. Cite the figure: the server writes the place next to it.']),
                ],
                'theme' => 'other',
                'operations' => ['change', 'change_pct', 'rank'],
            ], array_map(static fn (array $row): array => [$id, ...\array_slice($row, 1)], $rows)];
        }
        if ([] !== $problems) {
            throw new ImportRefused($problems);
        }
        if ([] === $entries) {
            throw self::refused('Name at least one column of numbers: its title says what the figures are.');
        }
        if ($this->store->count() + \count($entries) > ImportStore::MAX_ENTRIES) {
            throw self::refused('This Go Figure already keeps %max% added datasets, or would with this file.', ['%max%' => (string) ImportStore::MAX_ENTRIES]);
        }
        $kept = [];
        foreach ($entries as [$entry, $rows]) {
            try {
                $loaded = $this->loader->fromArray($entry);
            } catch (InvalidCatalog $e) {
                throw new ImportRefused(array_map(static fn (string $error): array => ['%error%', ['%error%' => $error]], $e->errors));
            }
            if (!$this->store->add($entry, $rows, ImportStore::MAX_ENTRIES)) {
                throw self::refused('This column of this file is already here.');
            }
            $kept[] = $loaded;
        }
        $this->catalog->reset();

        return $kept;
    }

    /** A number as people write it: "1234.5", "1 234,5", "1,234.5", "-12". Null for anything else. */
    public static function number(string $cell): ?float
    {
        $cell = (string) preg_replace('/[\s\x{A0}\x{202F}\']/u', '', $cell);
        if ('' === $cell || 1 !== preg_match('/^-?\d[\d.,]*$/D', $cell)) {
            return null;
        }
        [$dots, $commas] = [substr_count($cell, '.'), substr_count($cell, ',')];
        $cell = match (true) {
            $dots > 0 && $commas > 0 => strrpos($cell, ',') > strrpos($cell, '.') ? str_replace(',', '.', str_replace('.', '', $cell)) : str_replace(',', '', $cell),
            // One comma: a decimal, unless it groups thousands ("1,234"); several: thousands.
            $commas > 1 => str_replace(',', '', $cell),
            1 === $commas => 1 === preg_match('/^-?\d{1,3},\d{3}$/D', $cell) ? str_replace(',', '', $cell) : str_replace(',', '.', $cell),
            $dots > 1 => str_replace('.', '', $cell),
            default => $cell,
        };

        return is_numeric($cell) ? (float) $cell : null;
    }

    /** @param array<string, string> $parameters */
    private static function refused(string $reason, array $parameters = []): ImportRefused
    {
        return new ImportRefused([[$reason, $parameters]]);
    }
}
