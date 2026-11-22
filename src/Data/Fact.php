<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One row of the clean table (data/facts.sqlite), exactly as the extraction wrote it.
 */
final readonly class Fact
{
    public function __construct(
        public string $dataset,
        public string $geo,
        public string $time,
        public string $unit,
        /** Null when the source publishes no value for this cell. */
        public ?float $value,
        /** Source flag, e.g. "p" (provisional) or "e" (estimated); empty when none. */
        public string $status,
        public string $sourceUpdated,
        public string $snapshotDate,
    ) {
    }

    /**
     * @param array<string, mixed> $row a row of the facts table
     */
    public static function fromRow(array $row): self
    {
        return new self(
            dataset: (string) $row['dataset'],
            geo: (string) $row['geo'],
            time: (string) $row['time'],
            unit: (string) $row['unit'],
            value: null === $row['value'] ? null : (float) $row['value'],
            status: (string) $row['status'],
            sourceUpdated: (string) $row['source_updated'],
            snapshotDate: (string) $row['snapshot_date'],
        );
    }
}
