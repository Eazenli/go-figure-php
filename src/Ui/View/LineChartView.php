<?php

declare(strict_types=1);

namespace App\Ui\View;

use App\Proof\MeasuredProof;

final readonly class LineChartView
{
    public const string KIND = 'line';
    public const int WIDTH = 600;
    public const int HEIGHT = 200;
    /** Room above the plot for the label of its highest value, and below it for the periods. */
    public const int TOP = 28;
    public const int BOTTOM = 52;

    /**
     * @param list<Line>                                    $lines
     * @param list<array{y: string, proof: MeasuredProof, below: bool}> $grid a hairline at the highest and at the lowest proven value, labelled with that value (below the lowest hairline): the only numbers on the chart are proven ones
     * @param list<array{x: string, period: string, anchor: string}> $ticks the periods along the bottom
     */
    public function __construct(
        public Words $title,
        public array $lines,
        public array $grid = [],
        public array $ticks = [],
    ) {
    }
}
