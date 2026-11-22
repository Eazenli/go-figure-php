<?php

declare(strict_types=1);

namespace App\Import;

use App\Data\Fact;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The datasets imported on this machine (the extended mode): their catalog entries and their cells,
 * in a SQLite file of their own, written by the server, apart from the shipped snapshot that stays
 * read-only. The cells keep the date of their import: a proof stays true if Eurostat revises them.
 *
 * The file lives in var/: it goes with the container, and the shipped catalog never depends on it.
 */
final class ImportStore
{
    /** Every imported entry id starts with it: it never meets a shipped id. */
    public const string PREFIX = 'x_';
    public const int MAX_ENTRIES = 20;
    public const int MAX_CITIES = 30;

    private ?\Pdo\Sqlite $pdo = null;

    public function __construct(
        #[Autowire('%app.imported_path%')]
        private readonly string $path,
    ) {
    }

    public static function owns(string $dataset): bool
    {
        return str_starts_with($dataset, self::PREFIX);
    }

    /**
     * The entries, as data the catalog checks again when it reads them.
     *
     * @return list<array<mixed>>
     */
    public function entries(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $entries = [];
        $statement = $this->pdo()->query('SELECT data FROM entries ORDER BY id');
        foreach (false === $statement ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN) as $data) {
            $entry = json_decode((string) $data, true);
            if (\is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function count(): int
    {
        if (!is_file($this->path)) {
            return 0;
        }
        $statement = $this->pdo()->query('SELECT COUNT(*) FROM entries');

        return false === $statement ? 0 : (int) $statement->fetchColumn();
    }

    public function has(string $id): bool
    {
        if (!is_file($this->path)) {
            return false;
        }
        $statement = $this->pdo()->prepare('SELECT 1 FROM entries WHERE id = ?');
        $statement->execute([$id]);

        return false !== $statement->fetchColumn();
    }

    /**
     * A new entry and its cells, together or not at all: false if the id is already there or the
     * store is full (checked in the same transaction, so two imports at once cannot pass it).
     *
     * @param array<string, mixed>                                                         $entry
     * @param list<array{string, string, string, string, ?float, string, string, string}> $rows
     */
    public function add(array $entry, array $rows, int $max): bool
    {
        $pdo = $this->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $exists = $pdo->prepare('SELECT 1 FROM entries WHERE id = ?');
            $exists->execute([$entry['id']]);
            $count = $pdo->query('SELECT COUNT(*) FROM entries');
            if (false !== $exists->fetchColumn() || (false !== $count && (int) $count->fetchColumn() >= $max)) {
                $pdo->exec('ROLLBACK');

                return false;
            }
            $pdo->prepare('INSERT INTO entries (id, data) VALUES (?, ?)')->execute([$entry['id'], json_encode($entry, \JSON_THROW_ON_ERROR)]);
            $insert = $pdo->prepare('INSERT INTO facts VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($rows as $row) {
                $insert->execute($row);
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\PDOException) {
                // SQLite rolled back by itself: the error that matters is $e.
            }
            throw $e;
        }

        return true;
    }

    /**
     * The cities whose weather was added here: [code, English name, the point as the source link writes it].
     *
     * @return list<array{string, string, string}>
     */
    public function cities(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $statement = $this->pdo()->query('SELECT code, name, place FROM cities ORDER BY code');

        $cities = [];
        foreach (false === $statement ? [] : $statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            $cities[] = [(string) $row[0], (string) $row[1], (string) $row[2]];
        }

        return $cities;
    }

    /**
     * A city and its normals, together or not at all: false if it is there already or the store is full.
     *
     * @param list<array{string, string, string, string, ?float, string, string, string}> $rows
     */
    public function addCity(string $code, string $name, string $place, array $rows): bool
    {
        $pdo = $this->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $exists = $pdo->prepare('SELECT 1 FROM cities WHERE code = ?');
            $exists->execute([$code]);
            $count = $pdo->query('SELECT COUNT(*) FROM cities');
            if (false !== $exists->fetchColumn() || (false !== $count && (int) $count->fetchColumn() >= self::MAX_CITIES)) {
                $pdo->exec('ROLLBACK');

                return false;
            }
            $pdo->prepare('INSERT INTO cities (code, name, place) VALUES (?, ?, ?)')->execute([$code, $name, $place]);
            $insert = $pdo->prepare('INSERT INTO facts VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($rows as $row) {
                $insert->execute($row);
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\PDOException) {
            }
            throw $e;
        }

        return true;
    }

    /**
     * When the store last changed (another process may have imported: the STDIO MCP server reads
     * the catalog for hours); null if there is no store.
     */
    public function version(): ?string
    {
        clearstatcache(true, $this->path);

        return is_file($this->path) ? filemtime($this->path).':'.filesize($this->path) : null;
    }

    public function find(string $dataset, string $geo, string $time): ?Fact
    {
        if (!is_file($this->path)) {
            return null;
        }
        $statement = $this->pdo()->prepare('SELECT * FROM facts WHERE dataset = ? AND geo = ? AND time = ?');
        $statement->execute([$dataset, $geo, $time]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return \is_array($row) ? Fact::fromRow($row) : null;
    }

    /**
     * @return list<Fact>
     */
    public function series(string $dataset, string $geo, string $from, string $to): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $statement = $this->pdo()->prepare('SELECT * FROM facts WHERE dataset = ? AND geo = ? AND time >= ? AND time <= ? ORDER BY time');
        $statement->execute([$dataset, $geo, $from, $to]);

        return array_values(array_map(Fact::fromRow(...), $statement->fetchAll(\PDO::FETCH_ASSOC)));
    }

    private function pdo(): \Pdo\Sqlite
    {
        if (null === $this->pdo) {
            if (!is_dir(\dirname($this->path)) && !mkdir(\dirname($this->path), 0o775, true) && !is_dir(\dirname($this->path))) {
                throw new \RuntimeException(\sprintf('Cannot create the folder of %s.', $this->path));
            }
            $pdo = new \Pdo\Sqlite('sqlite:'.$this->path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('CREATE TABLE IF NOT EXISTS entries (id TEXT PRIMARY KEY, data TEXT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS cities (code TEXT PRIMARY KEY, name TEXT NOT NULL, place TEXT NOT NULL)');
            // The facts table, as data/refresh.py writes it.
            $pdo->exec('CREATE TABLE IF NOT EXISTS facts (
                dataset TEXT NOT NULL, geo TEXT NOT NULL, time TEXT NOT NULL, unit TEXT NOT NULL, value REAL,
                status TEXT NOT NULL DEFAULT \'\', source_updated TEXT NOT NULL, snapshot_date TEXT NOT NULL,
                PRIMARY KEY (dataset, geo, time))');
            $this->pdo = $pdo;
        }

        return $this->pdo;
    }
}
