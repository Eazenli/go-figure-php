<?php

declare(strict_types=1);

namespace App\Data;

use App\Import\ImportStore;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read-only access to the clean table written by the Python extraction (data/).
 *
 * The file is opened read-only and lazily: the app boots, and the home page works, even before the
 * first snapshot exists. The cells of a dataset imported on this machine (the extended mode) are
 * read from its own store.
 */
final class Facts
{
    private ?\Pdo\Sqlite $pdo = null;

    public function __construct(
        #[Autowire('%app.facts_path%')]
        private readonly string $path,
        private readonly ImportStore $imported = new ImportStore(''),
    ) {
    }

    public function find(string $dataset, string $geo, string $time): ?Fact
    {
        if (ImportStore::owns($dataset)) {
            return $this->imported->find($dataset, $geo, $time);
        }
        $row = $this->fetch(
            'SELECT * FROM facts WHERE dataset = ? AND geo = ? AND time = ?',
            [$dataset, $geo, $time],
        );

        // A city added on this machine: its normals are in the store, under the shipped dataset.
        return false === $row ? $this->imported->find($dataset, $geo, $time) : Fact::fromRow($row);
    }

    /**
     * Every row of one dataset in one place, from one period to another (both included), in order.
     *
     * Periods of one dataset share one format, so comparing the strings compares the periods.
     *
     * @return list<Fact>
     */
    public function series(string $dataset, string $geo, string $from, string $to): array
    {
        if (ImportStore::owns($dataset)) {
            return $this->imported->series($dataset, $geo, $from, $to);
        }
        $statement = $this->pdo()->prepare('SELECT * FROM facts WHERE dataset = ? AND geo = ? AND time >= ? AND time <= ? ORDER BY time');
        $statement->execute([$dataset, $geo, $from, $to]);

        $rows = array_values(array_map(Fact::fromRow(...), $statement->fetchAll(\PDO::FETCH_ASSOC)));

        return [] === $rows ? $this->imported->series($dataset, $geo, $from, $to) : $rows;
    }

    /**
     * @param list<string> $params
     *
     * @return array<string, mixed>|false
     */
    private function fetch(string $sql, array $params): array|false
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetch(\PDO::FETCH_ASSOC);
    }

    private function pdo(): \Pdo\Sqlite
    {
        if (null === $this->pdo) {
            if (!is_file($this->path)) {
                throw new NoSnapshot(\sprintf('No data snapshot at %s: run the extraction first.', $this->path));
            }

            $pdo = new \Pdo\Sqlite('sqlite:'.$this->path, options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READONLY,
            ]);
            // An empty or unfinished file is no snapshot either.
            $table = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'facts'");
            if (false === $table || false === $table->fetchColumn()) {
                throw new NoSnapshot(\sprintf('The file at %s has no facts table: run the extraction again.', $this->path));
            }
            $this->pdo = $pdo;
        }

        return $this->pdo;
    }
}
