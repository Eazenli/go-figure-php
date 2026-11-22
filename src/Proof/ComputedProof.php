<?php

declare(strict_types=1);

namespace App\Proof;

use App\Layout\Op;

/**
 * The proof of a figure the server computed from proven cells: the operation, the
 * proof of each cell, and the result. Anyone can redo it: every operand links to its own proof.
 *
 * Its link is short: it names the operation and the cells, and carries the fingerprint of the whole
 * proof. The proof page resolves the cells again, computes again, and compares the fingerprints.
 */
final readonly class ComputedProof
{
    /** In percent: the unit of a relative change and of a share. */
    public const string PERCENT = 'PC';
    public const string RANK = 'RANK';

    /**
     * @param non-empty-list<MeasuredProof> $operands in the order the operation reads them
     */
    public function __construct(
        public Op $op,
        public array $operands,
        /** The result, not rounded: rounding is for display only. */
        public float $value,
        /** The unit code of the result: the cells' own for a change, PC, or RANK. */
        public string $unit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => MeasuredProof::VERSION,
            'type' => 'computed',
            'op' => $this->op->value,
            'operands' => array_map(static fn (MeasuredProof $p): array => $p->toArray(), $this->operands),
            'unit' => $this->unit,
            'value' => $this->value,
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
        return ProofToken::encode([
            'v' => MeasuredProof::VERSION,
            'type' => 'computed',
            'op' => $this->op->value,
            'cells' => array_map(static fn (MeasuredProof $p): array => [$p->fact->dataset, $p->fact->geo, $p->fact->time], $this->operands),
            'fp' => $this->fingerprint(),
        ]);
    }
}
