<?php

declare(strict_types=1);

namespace App\Ui;

use App\Catalog\Attribution;
use App\Catalog\Places;
use App\Proof\ComputedProof;
use App\Proof\MeasuredProof;
use App\Proof\Refusal;
use App\Ui\Twig\FigureExtension;
use App\Ui\View\BarChartView;
use App\Ui\View\KpiView;
use App\Ui\View\LineChartView;
use App\Ui\View\NoteView;
use App\Ui\View\PageView;
use App\Ui\View\PeriodView;
use App\Ui\View\TableView;
use App\Ui\View\Words;

/**
 * The page as Markdown, for hosts that cannot show the HTML: every value is a link to its proof,
 * places and periods are code spans, refusals keep their reason. Like the HTML, it never shows a
 * number the server did not prove.
 */
final readonly class PageMarkdown
{
    public function __construct(
        private FigureExtension $figures,
        private Places $places,
    ) {
    }

    public function render(PageView $page): string
    {
        $blocks = ['# '.$this->words($page->title)];
        foreach ($page->components as $component) {
            $blocks[] = match (true) {
                $component instanceof KpiView => '## '.$this->words($component->label)."\n\n".$this->rows([$component->figure]),
                $component instanceof BarChartView => '## '.$this->words($component->title)."\n\n".$this->rows(array_column($component->bars, 'figure')),
                $component instanceof TableView => '## '.$this->words($component->title)."\n\n".$this->rows($component->rows, withDataset: true),
                $component instanceof LineChartView => '## '.$this->words($component->title)."\n\n".implode("\n", array_map(
                    fn ($line) => \is_array($line->points)
                        ? \sprintf('- %s: %s', $this->place($line->series->geo), implode(', ', array_map(fn (MeasuredProof $p) => \sprintf('`%s` %s', $p->fact->time, $this->figure($p)), $line->points)))
                        : '- '.$this->figure($line->points),
                    $component->lines,
                )),
                $component instanceof NoteView => implode('', array_map(fn (int $i) => $this->segment($component->segments[$i], $component->segments[$i - 1] ?? null), array_keys($component->segments))),
            };
        }

        // What the sources of the figures ask to show next to them, e.g. Open-Meteo's link.
        $credits = array_map(
            fn (Attribution $credit): string => null === $credit->url ? $this->plain($credit->text) : \sprintf('[%s](%s)', $this->plain($credit->text), $credit->url),
            $this->figures->credits($page),
        );
        if ([] !== $credits) {
            $blocks[] = 'Sources: '.implode('; ', $credits).'.';
        }

        return implode("\n\n", $blocks)."\n";
    }

    /**
     * @param list<MeasuredProof|ComputedProof|Refusal> $figures
     */
    private function rows(array $figures, bool $withDataset = false): string
    {
        return implode("\n", array_map(fn (MeasuredProof|ComputedProof|Refusal $f) => '- '.match (true) {
            $f instanceof ComputedProof => \sprintf('`%s`, %s: %s', $f->operands[0]->fact->dataset, $this->plain($this->figures->caption($f)), $this->figure($f)),
            $f instanceof MeasuredProof && $withDataset => \sprintf('`%s`, %s, `%s`: %s', $f->fact->dataset, $this->place($f->fact->geo), $f->fact->time, $this->figure($f)),
            $f instanceof MeasuredProof => \sprintf('%s, `%s`: %s', $this->place($f->fact->geo), $f->fact->time, $this->figure($f)),
            default => $this->figure($f),
        }, $figures));
    }

    private function segment(Words|MeasuredProof|ComputedProof|Refusal|PeriodView $segment, Words|MeasuredProof|ComputedProof|Refusal|PeriodView|null $previous): string
    {
        return match (true) {
            $segment instanceof Words => $this->words($this->figures->afterUnit($segment, $previous)),
            $segment instanceof PeriodView => match (true) {
                null !== $segment->problem => \sprintf('**%s**', $segment->problem),
                $segment->from === $segment->to => \sprintf('`%s`', $segment->from),
                default => \sprintf('`%s` to `%s`', $segment->from, $segment->to),
            },
            // In running text, the server says which cell a figure is: the words around it cannot.
            $segment instanceof MeasuredProof => \sprintf('%s (%s, `%s`)', $this->figure($segment), $this->plain($this->places->name($segment->fact->geo)), $segment->fact->time),
            $segment instanceof ComputedProof => \sprintf('%s (%s)', $this->figure($segment), $this->plain($this->figures->caption($segment))),
            default => $this->figure($segment),
        };
    }

    private function figure(MeasuredProof|ComputedProof|Refusal $figure): string
    {
        if ($figure instanceof Refusal) {
            return \sprintf('*%s: %s* (asked for `%s`)', $figure->reason->missing() ? 'Missing' : 'Refused', $figure->reason->message(), $this->figures->requested($figure, true));
        }

        $flag = implode(', ', FigureExtension::flagWords(FigureExtension::flags($figure)));
        $unit = $this->figures->unit($figure);

        return \sprintf(
            '[%s](%s)%s%s',
            $this->figures->number($figure),
            $this->figures->proofUrl($figure),
            '' === $unit ? '' : ' '.$this->plain($unit),
            '' === $flag ? '' : " ($flag)",
        );
    }

    /** A place as the HTML names it, with its code: "France `FR`", "Seville `ES004C`". */
    private function place(string $geo): string
    {
        $name = $this->places->name($geo);

        return $name === $geo ? \sprintf('`%s`', $geo) : \sprintf('%s `%s`', $this->plain($name), $geo);
    }

    private function words(Words $words): string
    {
        if (null !== $words->problem) {
            return \sprintf('**%s**', $words->problem);
        }

        return $this->plain($words->text);
    }

    /**
     * Text on one line, shown as written. The characters that would turn it into a link, a code
     * span, emphasis, a heading or an entity are escaped: "&#xBD;" has no digit, but a Markdown host
     * would show ½. So are the dots, colons and at signs that hosts turn into links on their own
     * ("evil.example", "https://…", "me@evil.example"): the only links of a page are written by the
     * server, to its proofs and to the sources' credits.
     */
    private function plain(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = (string) preg_replace('/[\\\\`*_\[\]<>|&@~#]/', '\\\\$0', $text);

        return (string) preg_replace('/\.(?=[\p{L}\p{N}])|:(?=\/)/u', '\\\\$0', $text);
    }
}
