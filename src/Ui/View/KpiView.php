<?php

declare(strict_types=1);

namespace App\Ui\View;

use App\Proof\ComputedProof;
use App\Proof\MeasuredProof;
use App\Proof\Refusal;

final readonly class KpiView
{
    public const string KIND = 'kpi';

    public function __construct(
        public Words $label,
        public MeasuredProof|ComputedProof|Refusal $figure,
    ) {
    }
}
