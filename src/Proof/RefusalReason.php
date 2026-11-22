<?php

declare(strict_types=1);

namespace App\Proof;

/**
 * Why a figure cannot be proven.
 */
enum RefusalReason: string
{
    case UnknownDataset = 'unknown_dataset';
    case PlaceNotCovered = 'place_not_covered';
    case CellMissing = 'cell_missing';
    case ValueMissing = 'value_missing';
    case OperandCount = 'operand_count';
    case OperandMissing = 'operand_missing';
    case OperationNotOffered = 'operation_not_offered';
    case NotAChange = 'not_a_change';
    case NotARank = 'not_a_rank';
    case NotAShare = 'not_a_share';
    case NoResult = 'no_result';
    case InvalidPeriod = 'invalid_period';
    case ScaleMismatch = 'scale_mismatch';

    /**
     * Whether the data simply has no figure there (a place, a period, a value the source does not
     * publish): said as "Missing". The other refusals are of what was asked for itself.
     */
    public function missing(): bool
    {
        return \in_array($this, [self::PlaceNotCovered, self::CellMissing, self::ValueMissing], true);
    }

    public function message(): string
    {
        return match ($this) {
            self::UnknownDataset => 'This dataset is not in the catalog.',
            self::PlaceNotCovered => 'This source has no data for this place.',
            self::CellMissing => 'This source has no data for this place and period.',
            self::ValueMissing => 'The source publishes no value for this place and period.',
            self::OperandCount => 'This calculation does not have the number of cells it needs.',
            self::OperandMissing => 'A cell of this calculation cannot be proven.',
            self::OperationNotOffered => 'This dataset does not allow this calculation.',
            self::NotAChange => 'Not a change: it needs one place at two periods, from the same dataset.',
            self::NotARank => 'Not a rank: it needs several places at one period, from the same dataset.',
            self::NotAShare => 'Not a share: it needs a part and its whole, for one place and one period.',
            self::NoResult => 'This calculation has no result: a division by zero, or a part larger than its whole.',
            self::InvalidPeriod => 'This period is not a year, a month or a month of the year.',
            self::ScaleMismatch => 'Another unit or another kind of period than the first figure of this chart: not on the same scale.',
        };
    }
}
