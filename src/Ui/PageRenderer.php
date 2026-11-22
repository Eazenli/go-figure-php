<?php

declare(strict_types=1);

namespace App\Ui;

use App\Catalog\Catalog;
use App\Layout\BarChart;
use App\Layout\Component;
use App\Layout\Constraint\FreeText;
use App\Layout\Constraint\Render;
use App\Layout\FigureRef;
use App\Layout\KpiTile;
use App\Layout\LineChart;
use App\Layout\Note;
use App\Layout\Page;
use App\Layout\PeriodRef;
use App\Layout\Segment;
use App\Layout\Table;
use App\Layout\Text;
use App\Proof\ComputedProof;
use App\Proof\MeasuredProof;
use App\Proof\Refusal;
use App\Proof\RefusalReason;
use App\Proof\Resolver;
use App\Ui\View\Bar;
use App\Ui\View\BarChartView;
use App\Ui\View\KpiView;
use App\Ui\View\Line;
use App\Ui\View\LineChartView;
use App\Ui\View\NoteView;
use App\Ui\View\PageView;
use App\Ui\View\PeriodView;
use App\Ui\View\TableView;
use App\Ui\View\Words;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Twig\Environment;

/**
 * Turns a layout into HTML: every figure is resolved to a proof or a refusal, every piece of model
 * text is checked against the layout rules, then the components are rendered by Twig.
 */
final readonly class PageRenderer
{
    /**
     * Far above what a page needs, and what a page shows at most: a layout of a few hundred bytes
     * could otherwise name thousands of cells (a /p link, render_page, /lie). The rest is not shown.
     */
    public const int MAX_COMPONENTS = 20;
    public const int MAX_ITEMS = 50;
    public const int MAX_SERIES = 8;
    public const int MAX_SEGMENTS = 60;

    public function __construct(
        private Resolver $resolver,
        private ValidatorInterface $validator,
        private Environment $twig,
        private Catalog $catalog,
    ) {
    }

    /** The page as an HTML fragment, for the web page and for MCP Apps alike. */
    public function render(Page $page): string
    {
        return $this->twig->render('page/page.html.twig', ['page' => $this->view($page)]);
    }

    public function view(Page $page): PageView
    {
        return new PageView($this->words($page, 'title'), array_map($this->componentView(...), \array_slice($page->components, 0, self::MAX_COMPONENTS)));
    }

    /**
     * The page title alone, for a page that streams in.
     *
     * @param int $heading the level of the title, where the page sits (components take the next)
     */
    public function renderTitle(Page $page, int $heading = 1): string
    {
        return $this->twig->render('page/_title.html.twig', ['title' => $this->words($page, 'title'), 'heading' => $heading]);
    }

    /** What the sources of a page's figures ask to show next to them, for a page that streamed in. */
    public function renderCredits(PageView $view): string
    {
        return $this->twig->render('page/_credits.html.twig', ['page' => $view]);
    }

    /** One component alone, for a page that streams in: its figures are resolved now. */
    public function renderComponent(Component $component, int $heading = 1): string
    {
        $view = $this->componentView($component);

        return $this->twig->render('page/'.$view::KIND.'.html.twig', ['c' => $view, 'heading' => $heading]);
    }

    private function componentView(Component $component): KpiView|BarChartView|LineChartView|TableView|NoteView
    {
        return match (true) {
            $component instanceof KpiTile => new KpiView($this->words($component, 'label'), $this->resolver->figure($component->figure)),
            $component instanceof BarChart => $this->barChart($component),
            $component instanceof LineChart => $this->lineChart($component),
            $component instanceof Table => new TableView($this->words($component, 'title'), array_map($this->resolver->figure(...), \array_slice($component->rows, 0, self::MAX_ITEMS))),
            $component instanceof Note => new NoteView(self::spaced(array_map($this->segment(...), self::joined(\array_slice($component->segments, 0, self::MAX_SEGMENTS))))),
            default => throw new \LogicException(\sprintf('Unknown component %s.', $component::class)),
        };
    }

    /**
     * Bars on one scale: a bar in another unit than the first proven one is refused, not drawn
     * against it. A bar's length is its share of the largest value; a value at or below zero draws
     * no bar, only its value (a bar never stands for a negative value).
     */
    private function barChart(BarChart $chart): BarChartView
    {
        $figures = array_map($this->resolver->figure(...), \array_slice($chart->bars, 0, self::MAX_ITEMS));
        $scale = null; // the unit and the kind of period of the first proven bar
        foreach ($figures as $i => $figure) {
            if (!$figure instanceof Refusal) {
                // A measured value and a computed one are never on one scale, nor two operations.
                $own = $figure instanceof MeasuredProof ? [$figure->fact->unit, \strlen($figure->fact->time)] : [$figure->unit, $figure->op->value, \strlen($figure->operands[0]->fact->time)];
                $scale ??= $own;
                if ($own !== $scale) {
                    $figures[$i] = new Refusal(RefusalReason::ScaleMismatch, $chart->bars[$i]);
                }
            }
        }
        // A rank draws no bar: the first place is not the smallest.
        $length = static fn (MeasuredProof|ComputedProof|Refusal $f): float => $f instanceof Refusal || ($f instanceof ComputedProof && ComputedProof::RANK === $f->unit) ? 0.0 : $f->value;
        $max = max([0.0, ...array_map($length, $figures)]);

        return new BarChartView($this->words($chart, 'title'), array_map(
            static fn (MeasuredProof|ComputedProof|Refusal $f) => new Bar($f, $max > 0 && $length($f) > 0 ? self::percent($length($f) / $max) : '0%'),
            $figures,
        ));
    }

    private function lineChart(LineChart $chart): LineChartView
    {
        $lines = [];
        $scale = null; // the unit and the kind of period of the first proven series
        foreach (\array_slice($chart->series, 0, self::MAX_SERIES) as $series) {
            $problem = $this->validator->validate($series, groups: [Render::GROUP]);
            $points = \count($problem) > 0 ? new Refusal(RefusalReason::InvalidPeriod, $series) : $this->resolver->series($series);
            if (\is_array($points)) {
                $scale ??= [$points[0]->fact->unit, \strlen($points[0]->fact->time)];
                if ([$points[0]->fact->unit, \strlen($points[0]->fact->time)] !== $scale) {
                    $points = new Refusal(RefusalReason::ScaleMismatch, $series);
                }
            }
            $lines[] = new Line($series, $points);
        }

        // One scale for all lines: x follows every period asked for that the dataset covers, so a
        // missing year keeps its place and breaks the line, and no period the model made up (a
        // "4576") reaches the axis; y spans every proven value.
        $points = array_merge(...array_map(static fn (Line $line) => \is_array($line->points) ? $line->points : [], $lines));
        if ([] === $points) {
            return new LineChartView($this->words($chart, 'title'), $lines);
        }
        $periods = [];
        foreach ($lines as $line) {
            if (\is_array($line->points)) {
                $covered = $this->catalog->get($line->series->dataset)?->time->periods() ?? [];
                $periods = [...$periods, ...array_intersect(self::periodsBetween($line->series->from, $line->series->to), $covered)];
            }
        }
        $periods = array_values(array_unique([...$periods, ...array_map(static fn (MeasuredProof $p) => $p->fact->time, $points)]));
        sort($periods);
        $x = array_flip($periods);
        $values = array_map(static fn (MeasuredProof $p) => $p->value, $points);
        [$min, $max] = [min($values), max($values)];

        $w = LineChartView::WIDTH - 20;
        $h = LineChartView::HEIGHT - LineChartView::TOP - LineChartView::BOTTOM;
        $xOf = static fn (int $i): string => self::number(10 + (\count($periods) > 1 ? $i / (\count($periods) - 1) : 0.5) * $w);
        $yOf = static fn (float $value): string => self::number(LineChartView::TOP + ($max > $min ? 1 - ($value - $min) / ($max - $min) : 0.5) * $h);
        $place = static fn (MeasuredProof $p) => ['x' => $xOf($x[$p->fact->time]), 'y' => $yOf($p->value), 'proof' => $p];

        // The scale, told by proven values only: the lowest and the highest point, each a proof.
        $lowest = $highest = $points[0];
        foreach ($points as $point) {
            $lowest = $point->value < $lowest->value ? $point : $lowest;
            $highest = $point->value > $highest->value ? $point : $highest;
        }
        // The highest value's label above its hairline, the lowest's below its own: outside the lines.
        $grid = [['y' => $yOf($highest->value), 'proof' => $highest, 'below' => false]];
        if ($max > $min) {
            $grid[] = ['y' => $yOf($lowest->value), 'proof' => $lowest, 'below' => true];
        }
        // The periods along the bottom: all of them up to eight, else evenly spaced, counted back from the
        // last (the latest period is always shown, and the gaps are equal).
        $n = \count($periods);
        $step = (int) ceil(($n - 1) / 5);
        $shown = $n <= 8 ? array_keys($periods) : array_reverse(range($n - 1, 0, max(1, $step)));
        $ticks = array_map(static fn (int $i): array => [
            'x' => $xOf($i),
            'period' => $periods[$i],
            'anchor' => 0 === $i && \count($periods) > 1 ? 'start' : ($i === \count($periods) - 1 && \count($periods) > 1 ? 'end' : 'middle'),
        ], $shown);

        return new LineChartView($this->words($chart, 'title'), array_map(
            static function (Line $line) use ($place, $x): Line {
                if (!\is_array($line->points)) {
                    return $line;
                }
                $marks = array_map($place, $line->points);
                // A run of consecutive periods is one polyline: a missing period breaks the line.
                $runs = [];
                $previous = null;
                foreach ($marks as $mark) {
                    $i = $x[$mark['proof']->fact->time];
                    if (null === $previous || $i !== $previous + 1) {
                        $runs[] = '';
                    }
                    $runs[array_key_last($runs)] .= $mark['x'].','.$mark['y'].' ';
                    $previous = $i;
                }

                return new Line($line->series, $line->points, $marks, array_map(trim(...), $runs));
            },
            $lines,
        ), $grid, $ticks);
    }

    /**
     * Every period from $from to $to: years, months (2024-01) or months of the year (M01).
     *
     * @return list<string>
     */
    private static function periodsBetween(string $from, string $to): array
    {
        return match (true) {
            1 === preg_match('/^\d{4}$/D', $from) && 1 === preg_match('/^\d{4}$/D', $to) && (int) $to - (int) $from < 200 => array_map(strval(...), range((int) $from, max((int) $from, (int) $to))),
            1 === preg_match('/^M(0[1-9]|1[0-2])$/D', $from) && 1 === preg_match('/^M(0[1-9]|1[0-2])$/D', $to) => array_map(static fn (int $m) => \sprintf('M%02d', $m), range((int) substr($from, 1), max((int) substr($from, 1), (int) substr($to, 1)))),
            1 === preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $from) && 1 === preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $to) && $from <= $to && $to < ((int) substr($from, 0, 4) + 50).'-01' => self::months($from, $to),
            default => [$from, $to],
        };
    }

    /**
     * @return list<string>
     */
    private static function months(string $from, string $to): array
    {
        $months = [];
        for ($month = new \DateTimeImmutable($from.'-01'); ($period = $month->format('Y-m')) <= $to; $month = $month->modify('+1 month')) {
            $months[] = $period;
        }

        return $months;
    }

    private function segment(object $segment): Words|MeasuredProof|ComputedProof|Refusal|PeriodView
    {
        return match (true) {
            $segment instanceof Text => $this->words($segment, 'text'),
            $segment instanceof FigureRef => $this->resolver->figure($segment->figure),
            $segment instanceof PeriodRef => $this->period($segment),
            default => throw new \LogicException(\sprintf('Unknown segment %s.', $segment::class)),
        };
    }

    /**
     * Consecutive text segments as one: "nin" then "ety" are checked, and shown, as "ninety".
     *
     * @param list<Segment> $segments
     *
     * @return list<Segment>
     */
    private static function joined(array $segments): array
    {
        $joined = [];
        foreach ($segments as $segment) {
            $last = [] === $joined ? null : $joined[\count($joined) - 1];
            if ($segment instanceof Text && $last instanceof Text) {
                $joined[\count($joined) - 1] = new Text($last->kind, $last->text.$segment->text);
            } else {
                $joined[] = $segment;
            }
        }

        return $joined;
    }

    /**
     * Keeps every figure and period of a note apart from its neighbours: "2019" right before "2024",
     * or a figure right after "-", would read as another number. A space goes wherever the model left
     * none, except after an opening parenthesis and before closing punctuation ("in 2024.").
     *
     * @param list<Words|MeasuredProof|ComputedProof|Refusal|PeriodView> $segments
     *
     * @return list<Words|MeasuredProof|ComputedProof|Refusal|PeriodView>
     */
    private static function spaced(array $segments): array
    {
        $spaced = [];
        foreach ($segments as $segment) {
            $previous = [] === $spaced ? null : $spaced[\count($spaced) - 1];
            if ($previous instanceof Words && !$segment instanceof Words) {
                if (null === $previous->problem && 1 !== preg_match('/[\s(]$/u', $previous->text)) {
                    $spaced[\count($spaced) - 1] = new Words($previous->text.' ');
                }
            } elseif (null !== $previous && !$previous instanceof Words) {
                if (!$segment instanceof Words) {
                    $spaced[] = new Words(' ');
                } elseif (null === $segment->problem && '' !== $segment->text && 1 !== preg_match('/^[\s.,;:!?)]/u', $segment->text)) {
                    $segment = new Words(' '.$segment->text);
                }
            }
            $spaced[] = $segment;
        }

        return $spaced;
    }

    private function period(PeriodRef $period): PeriodView
    {
        $violations = $this->validator->validate($period, groups: [Render::GROUP]);
        if (\count($violations) > 0) {
            return new PeriodView('', '', $violations->get(0)->getMessageTemplate());
        }
        if (\strlen($period->from) !== \strlen($period->to) || $period->from > $period->to) {
            return new PeriodView('', '', 'This period ends before it starts.');
        }
        // "France counted 4576 nights" would pass as a period: only years the data covers are shown.
        if (!$this->catalog->knowsPeriod($period->from) || !$this->catalog->knowsPeriod($period->to)) {
            return new PeriodView('', '', 'The data has no such period.');
        }

        return new PeriodView($period->from, $period->to);
    }

    private function words(object $object, string $property): Words
    {
        $violations = $this->validator->validateProperty($object, $property, [Render::GROUP]);
        $text = $object->{$property};
        \assert(\is_string($text));

        // The message in English (its template): the page translates it where it shows it, and the
        // logs and MCP keep it as it is.
        return \count($violations) > 0 ? new Words('', $violations->get(0)->getMessageTemplate(), FreeText::blanked($text)) : new Words($text);
    }

    private static function percent(float $ratio): string
    {
        return self::number($ratio * 100).'%';
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
