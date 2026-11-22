<?php

declare(strict_types=1);

namespace App\Eval;

use App\Layout\BarChart;
use App\Layout\Computed;
use App\Layout\FigureRef;
use App\Layout\KpiTile;
use App\Layout\LineChart;
use App\Layout\Measured;
use App\Layout\Note;
use App\Layout\Page;
use App\Layout\Series;
use App\Layout\Table;
use App\Proof\ComputedProof;
use App\Proof\MeasuredProof;
use App\Proof\Resolver;
use App\Ui\PageRenderer;

/**
 * Scores the cells a model named for a question against the cells expected.
 *
 * - good: proven, and one of the expected cells (dataset, period, and place when the question names
 *   one);
 * - sourced but wrong: proven, but another dataset, period or place than expected. The most
 *   dangerous kind: the page shows a true figure that does not answer the question. Target 0;
 * - refused: the server could not prove it, and the page showed a refusal;
 * - calculations: counted, and each cell a calculation reads is scored like any other
 *   (one refusal if the calculation is refused);
 * - texts not shown: free text the page refused (a number in words, a sign…). The page never shows
 *   them; the count says how often the model tried.
 */
final readonly class Scorer
{
    public function __construct(
        private Resolver $resolver,
        private PageRenderer $pages,
    ) {
    }

    /**
     * @param list<array{dataset: string, time?: list<string>, geo?: list<string>}> $expected
     *
     * @return array{good: int, wrong: int, refused: int, calculations: int, texts: int}
     */
    public function score(Page $page, array $expected): array
    {
        $score = ['good' => 0, 'wrong' => 0, 'refused' => 0, 'calculations' => 0, 'texts' => 0];
        foreach ($this->cells($page) as $cell) {
            if ($cell instanceof Computed) {
                // A calculation is counted, and each cell it reads is scored like any other: a model
                // cannot dodge the "sourced but wrong" count by computing from the wrong cells.
                ++$score['calculations'];
                if (!$this->resolver->computed($cell) instanceof ComputedProof) {
                    ++$score['refused'];
                    continue;
                }
                foreach ($cell->operands as $operand) {
                    $score[self::expects($expected, $operand->dataset, [$operand->time], $operand->geo) ? 'good' : 'wrong']++;
                }
            } elseif ($cell instanceof Measured) {
                $score[$this->resolver->measured($cell) instanceof MeasuredProof ? (self::expects($expected, $cell->dataset, [$cell->time], $cell->geo) ? 'good' : 'wrong') : 'refused']++;
            } else {
                $score[\is_array($this->resolver->series($cell)) ? (self::expects($expected, $cell->dataset, [$cell->from, $cell->to], $cell->geo) ? 'good' : 'wrong') : 'refused']++;
            }
        }
        $score['texts'] = $this->pages->view($page)->tally()['hidden'];

        return $score;
    }

    /**
     * The places a right answer names for a dataset and the page does not: a page with one of six
     * expected cells is not a whole answer, however good that cell.
     *
     * @param list<array{dataset: string, time?: list<string>, geo?: list<string>, must?: list<string>}> $expected
     *
     * @return list<string> "dataset, place" for each one missing
     */
    public function missing(Page $page, array $expected): array
    {
        $named = [];
        foreach ($this->cells($page) as $cell) {
            foreach ($cell instanceof Computed ? $cell->operands : [$cell] as $one) {
                $named[$one->dataset.', '.$one->geo] = true;
            }
        }
        // A question with two readings is answered by either: the datasets of one group are
        // alternatives when they share their places.
        $missing = [];
        foreach ($expected as $cell) {
            // "must": the places a right answer cannot do without, among others it may show. The
            // cheapest of all must be on a page that says "the cheapest": a ranking the wrong way
            // round shows ten real figures, and none of these.
            foreach ($cell['must'] ?? [] as $geo) {
                if (!isset($named[$cell['dataset'].', '.$geo])) {
                    $missing[$cell['dataset'].', '.$geo] = true;
                }
            }
            foreach ($cell['geo'] ?? [] as $geo) {
                $alternatives = array_filter($expected, static fn (array $other): bool => \in_array($geo, $other['geo'] ?? [], true));
                if ([] === array_filter($alternatives, static fn (array $other): bool => isset($named[$other['dataset'].', '.$geo]))) {
                    $missing[$cell['dataset'].', '.$geo] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($missing));
    }

    /**
     * @param list<array{dataset: string, time?: list<string>, geo?: list<string>}> $expected
     * @param list<string>                                                         $times    the period of a cell, or the first and last of a series
     */
    private static function expects(array $expected, string $dataset, array $times, string $geo): bool
    {
        foreach ($expected as $cell) {
            if ($cell['dataset'] === $dataset
                && ([] === ($cell['time'] ?? []) || [] !== array_intersect($times, $cell['time']))
                && ([] === ($cell['geo'] ?? []) || \in_array($geo, $cell['geo'], true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Measured|Computed|Series> every cell the page names
     */
    private function cells(Page $page): array
    {
        $cells = [];
        foreach ($page->components as $component) {
            $named = match (true) {
                $component instanceof KpiTile => [$component->figure],
                $component instanceof BarChart => $component->bars,
                $component instanceof Table => $component->rows,
                $component instanceof LineChart => $component->series,
                $component instanceof Note => array_map(static fn (FigureRef $s) => $s->figure, array_values(array_filter($component->segments, static fn ($s) => $s instanceof FigureRef))),
                default => [],
            };
            foreach ($named as $cell) {
                if ($cell instanceof Measured || $cell instanceof Computed || $cell instanceof Series) {
                    $cells[] = $cell;
                }
            }
        }

        return $cells;
    }
}
