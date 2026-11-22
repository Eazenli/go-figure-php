<?php

declare(strict_types=1);

namespace App\Ui\View;

use App\Proof\ComputedProof;
use App\Proof\MeasuredProof;
use App\Proof\Refusal;

final readonly class PageView
{
    /**
     * @param list<KpiView|BarChartView|LineChartView|TableView|NoteView> $components
     */
    public function __construct(
        public Words $title,
        public array $components,
    ) {
    }

    /**
     * Everything the page shows, in reading order: the words written by the model, each figure
     * (a proof or a refusal) and each period of a note.
     *
     * @return list<Words|MeasuredProof|ComputedProof|Refusal|PeriodView>
     */
    public function parts(): array
    {
        $parts = [$this->title];
        foreach ($this->components as $component) {
            array_push($parts, ...self::partsOf($component));
        }

        return $parts;
    }

    /**
     * Everything one block shows, in reading order.
     *
     * @return list<Words|MeasuredProof|ComputedProof|Refusal|PeriodView>
     */
    public static function partsOf(KpiView|BarChartView|LineChartView|TableView|NoteView $component): array
    {
        return match (true) {
            $component instanceof KpiView => [$component->label, $component->figure],
            $component instanceof BarChartView => [$component->title, ...array_map(static fn (Bar $bar) => $bar->figure, $component->bars)],
            $component instanceof TableView => [$component->title, ...$component->rows],
            $component instanceof LineChartView => [$component->title, ...array_merge(...array_map(static fn (Line $line) => \is_array($line->points) ? $line->points : [$line->points], $component->lines))],
            $component instanceof NoteView => $component->segments,
        };
    }

    /**
     * @return array{proven: int, refused: int, hidden: int} the figures proven, the figures
     *                                                       refused, and the texts or periods not shown
     */
    public function tally(): array
    {
        $tally = ['proven' => 0, 'refused' => 0, 'hidden' => 0];
        foreach ($this->parts() as $part) {
            match (true) {
                $part instanceof MeasuredProof, $part instanceof ComputedProof => ++$tally['proven'],
                $part instanceof Refusal => ++$tally['refused'],
                null !== $part->problem => ++$tally['hidden'],
                default => null,
            };
        }

        return $tally;
    }
}
