<?php

declare(strict_types=1);

namespace App\Ui\View;

use App\Layout\Series;
use App\Proof\MeasuredProof;
use App\Proof\Refusal;

final readonly class Line
{
    /**
     * @param list<MeasuredProof>|Refusal $points
     * @param list<array{x: string, y: string, proof: MeasuredProof}> $marks SVG coordinates, as strings
     * @param list<string>                                           $runs  the points of each unbroken part of the line
     */
    public function __construct(
        public Series $series,
        public array|Refusal $points,
        public array $marks = [],
        public array $runs = [],
    ) {
    }
}
