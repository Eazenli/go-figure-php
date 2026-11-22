<?php

declare(strict_types=1);

namespace App\Ui\View;

use App\Proof\ComputedProof;
use App\Proof\MeasuredProof;
use App\Proof\Refusal;

final readonly class TableView
{
    public const string KIND = 'table';

    /**
     * @param list<MeasuredProof|ComputedProof|Refusal> $rows
     */
    public function __construct(
        public Words $title,
        public array $rows,
    ) {
    }
}
