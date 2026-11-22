<?php

declare(strict_types=1);

namespace App\Ui\View;

final readonly class BarChartView
{
    public const string KIND = 'bar';

    /**
     * @param list<Bar> $bars
     */
    public function __construct(
        public Words $title,
        public array $bars,
    ) {
    }
}
