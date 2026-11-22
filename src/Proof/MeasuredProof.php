<?php

declare(strict_types=1);

namespace App\Proof;

use App\Data\Fact;

/**
 * The proof of one cell: what the value is, where it comes from, and when it was read.
 */
final readonly class MeasuredProof
{
    public const int VERSION = 1;

    public function __construct(
        public Source $source,
        public Fact $fact,
        /** Never null: a cell without a value is refused, not proven. */
        public float $value,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'type' => 'measured',
            'provider' => $this->source->provider,
            'dataset' => $this->fact->dataset,
            'geo' => $this->fact->geo,
            'time' => $this->fact->time,
            'unit' => $this->fact->unit,
            'value' => $this->value,
            'status' => $this->fact->status,
            'source_updated' => $this->fact->sourceUpdated,
            'snapshot_date' => $this->fact->snapshotDate,
            'url' => $this->source->url,
        ];
    }

    public function canonical(): string
    {
        return Canonical::encode($this->toArray());
    }

    public function fingerprint(): string
    {
        return Canonical::fingerprint($this->toArray());
    }

    public function token(): string
    {
        return ProofToken::encode($this->toArray());
    }
}
