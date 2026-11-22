<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Writes a small clean table with the schema of the facts table, for the tests.
 *
 * The values are nights spent in tourist accommodation (Eurostat tour_occ_ninat, updated
 * 2026-10-12), typed by hand from a snapshot taken on 2026-11-05. The MT row is made up: it stands
 * for a cell the source publishes without a value. The temp_max_city rows are made up too, close to
 * what Open-Meteo gives for September (a normal by city, tests/fixtures/catalog/temp_max_city.yaml).
 */
final class FactsFixture
{
    public const string SOURCE_UPDATED = '2026-10-12';
    public const string SNAPSHOT_DATE = '2026-11-05';

    /** @var list<array{string, string, string, string, ?float, string}> dataset, geo, time, unit, value, status */
    private const array ROWS = [
        ['nights_total', 'ES', '2019', 'NR', 469813551, ''],
        ['nights_total', 'ES', '2024', 'NR', 505166012, ''],
        ['nights_total', 'FR', '2019', 'NR', 446553868, ''],
        ['nights_total', 'FR', '2020', 'NR', 257875315, 'e'],
        ['nights_total', 'FR', '2023', 'NR', 460271796, ''],
        ['nights_total', 'FR', '2024', 'NR', 457644262, ''],
        ['nights_total', 'MT', '2025', 'NR', null, ''],
        ['temp_max_city', 'ES004C', 'M09', 'degC', 31.1, ''],
        ['temp_max_city', 'PT001C', 'M09', 'degC', 26.4, ''],
        // Made up as well, below zero on purpose: a bar never stands for a negative value.
        ['temp_max_city', 'ES004C', 'M01', 'degC', -1.5, ''],
        ['temp_max_city', 'PT001C', 'M01', 'degC', 14.2, ''],
    ];

    public static function write(string $path): void
    {
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }
        if (is_file($path)) {
            unlink($path);
        }
        // What a test imported (the store of the extended mode sits beside the snapshot) is not kept
        // for the next one.
        if (is_file(\dirname($path).'/imported.sqlite')) {
            unlink(\dirname($path).'/imported.sqlite');
        }

        $pdo = new \Pdo\Sqlite('sqlite:'.$path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(<<<'SQL'
            CREATE TABLE facts (
              dataset        TEXT NOT NULL,
              geo            TEXT NOT NULL,
              time           TEXT NOT NULL,
              unit           TEXT NOT NULL,
              value          REAL,
              status         TEXT NOT NULL DEFAULT '',
              source_updated TEXT NOT NULL,
              snapshot_date  TEXT NOT NULL,
              PRIMARY KEY (dataset, geo, time)
            )
            SQL);

        $insert = $pdo->prepare('INSERT INTO facts VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (self::ROWS as [$dataset, $geo, $time, $unit, $value, $status]) {
            $insert->execute([$dataset, $geo, $time, $unit, $value, $status, self::SOURCE_UPDATED, self::SNAPSHOT_DATE]);
        }
    }
}
