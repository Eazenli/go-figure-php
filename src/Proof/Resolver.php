<?php

declare(strict_types=1);

namespace App\Proof;

use App\Catalog\Catalog;
use App\Catalog\Places;
use App\Data\Facts;
use App\Layout\Computed;
use App\Layout\Figure;
use App\Layout\Measured;
use App\Layout\Op;
use App\Layout\Series;

/**
 * Turns a cell named by the model into a proof, or into a refusal. The only way a value reaches a
 * page.
 *
 * measured figures and calculations on them. Computed figures wait for the operations.
 */
final readonly class Resolver
{
    public function __construct(
        private Catalog $catalog,
        private Facts $facts,
    ) {
    }

    /** A calculation reads at most this many cells: a rank among a handful of places. */
    public const int MAX_OPERANDS = 8;

    public function figure(Figure $figure): MeasuredProof|ComputedProof|Refusal
    {
        return match (true) {
            $figure instanceof Measured => $this->measured($figure),
            $figure instanceof Computed => $this->computed($figure),
            default => throw new \LogicException(\sprintf('Unknown figure %s.', $figure::class)),
        };
    }

    /**
     * A calculation on proven cells, at one level. The model names the operation and
     * the cells; the server checks that they are comparable, then computes. Never a composite score:
     * every operation stays within one dataset, or one source and unit.
     */
    public function computed(Computed $figure): ComputedProof|Refusal
    {
        $refuse = static fn (RefusalReason $reason): Refusal => new Refusal($reason, $figure);
        $count = \count($figure->operands);
        if (Op::Rank === $figure->op ? $count < 2 || $count > self::MAX_OPERANDS : 2 !== $count) {
            return $refuse(RefusalReason::OperandCount);
        }
        $proofs = [];
        foreach ($figure->operands as $operand) {
            $proof = $this->measured($operand);
            if ($proof instanceof Refusal) {
                return $refuse(RefusalReason::OperandMissing);
            }
            $proofs[] = $proof;
        }
        [$a, $b] = $proofs;
        $entry = $this->catalog->get($a->fact->dataset);
        if (null === $entry || !\in_array($figure->op, $entry->operations, true)) {
            return $refuse(RefusalReason::OperationNotOffered);
        }

        $sameDataset = 1 === \count(array_unique(array_map(static fn (MeasuredProof $p): string => $p->fact->dataset, $proofs)));
        $sameUnit = 1 === \count(array_unique(array_map(static fn (MeasuredProof $p): string => $p->fact->unit, $proofs)));
        switch ($figure->op) {
            case Op::Change:
            case Op::ChangePct:
                // The same dataset and the same kind of period: hotels in one year against all nights in another would mean nothing.
                // An evolution: one place, two different periods. Two places are compared by a rank.
                if (!$sameDataset || \strlen($a->fact->time) !== \strlen($b->fact->time) || $a->fact->geo !== $b->fact->geo || $a->fact->time === $b->fact->time) {
                    return $refuse(RefusalReason::NotAChange);
                }
                if (Op::Change === $figure->op) {
                    return new ComputedProof($figure->op, $proofs, $b->value - $a->value, $a->fact->unit);
                }

                return 0.0 === $a->value ? $refuse(RefusalReason::NoResult) : new ComputedProof($figure->op, $proofs, ($b->value - $a->value) / abs($a->value) * 100 /* the base in absolute value: a rise from a negative value stays a rise */, ComputedProof::PERCENT);
            case Op::Share:
                // A part and its whole: the same source dataset, unit, place and period (hotel nights in all nights).
                $whole = $this->catalog->get($b->fact->dataset);
                if (!$sameUnit || null === $whole || $whole->provider !== $entry->provider || $whole->sourceDataset !== $entry->sourceDataset
                    || $a->fact->geo !== $b->fact->geo || $a->fact->time !== $b->fact->time || $sameDataset) {
                    return $refuse(RefusalReason::NotAShare);
                }

                return $b->value <= 0.0 || $a->value < 0.0 || $a->value > $b->value ? $refuse(RefusalReason::NoResult) : new ComputedProof($figure->op, $proofs, $a->value / $b->value * 100, ComputedProof::PERCENT);
            case Op::Rank:
                // One dataset, one period, different places: the place of the first among them, highest first.
                $sameTime = 1 === \count(array_unique(array_map(static fn (MeasuredProof $p): string => $p->fact->time, $proofs)));
                $places = array_map(static fn (MeasuredProof $p): string => $p->fact->geo, $proofs);
                // Countries among countries, cities among cities: never an aggregate (the EU) among them.
                $kinds = array_unique(array_map(static fn (string $g): string => Places::isCity($g) ? 'city' : (Places::isCountry($g) ? 'country' : 'aggregate'), $places));
                if (!$sameDataset || !$sameTime || \count(array_unique($places)) !== $count || 1 !== \count($kinds) || 'aggregate' === $kinds[0]) {
                    return $refuse(RefusalReason::NotARank);
                }

                return new ComputedProof($figure->op, $proofs, 1.0 + \count(array_filter($proofs, static fn (MeasuredProof $p): bool => $p->value > $a->value)), ComputedProof::RANK);
        }
    }

    public function measured(Measured $figure): MeasuredProof|Refusal
    {
        $entry = $this->catalog->get($figure->dataset);
        if (null === $entry) {
            return new Refusal(RefusalReason::UnknownDataset, $figure);
        }
        if (!$entry->covers($figure->geo)) {
            return new Refusal(RefusalReason::PlaceNotCovered, $figure);
        }

        $fact = $this->facts->find($figure->dataset, $figure->geo, $figure->time);
        if (null === $fact) {
            return new Refusal(RefusalReason::CellMissing, $figure);
        }
        if (null === $fact->value) {
            return new Refusal(RefusalReason::ValueMissing, $figure);
        }

        return new MeasuredProof($entry->source($figure->geo), $fact, $fact->value);
    }

    /**
     * Every proven point of a series. Periods without a value are left out, so a line shows a gap
     * rather than a made-up point.
     *
     * @return list<MeasuredProof>|Refusal
     */
    public function series(Series $series): array|Refusal
    {
        $entry = $this->catalog->get($series->dataset);
        if (null === $entry) {
            return new Refusal(RefusalReason::UnknownDataset, $series);
        }
        if (!$entry->covers($series->geo)) {
            return new Refusal(RefusalReason::PlaceNotCovered, $series);
        }
        if (\strlen($series->from) !== \strlen($series->to) || $series->from > $series->to) {
            return new Refusal(RefusalReason::InvalidPeriod, $series);
        }

        $source = $entry->source($series->geo);
        $points = [];
        foreach ($this->facts->series($series->dataset, $series->geo, $series->from, $series->to) as $fact) {
            if (null !== $fact->value) {
                $points[] = new MeasuredProof($source, $fact, $fact->value);
            }
        }

        return [] === $points ? new Refusal(RefusalReason::CellMissing, $series) : $points;
    }
}
