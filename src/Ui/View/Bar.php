<?php

declare(strict_types=1);

namespace App\Ui\View;

use App\Proof\ComputedProof;
use App\Proof\MeasuredProof;
use App\Proof\Refusal;

final readonly class Bar
{
    public function __construct(
        public MeasuredProof|ComputedProof|Refusal $figure,
        /** CSS width of the bar, e.g. "63.2%": a style, never printed as text. */
        public string $width,
    ) {
    }
}
