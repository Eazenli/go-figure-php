<?php

declare(strict_types=1);

namespace App\Ui;

use App\Catalog\Attribution;
use App\Catalog\Catalog;
use App\Proof\ComputedProof;
use App\Proof\MeasuredProof;
use App\Ui\Twig\FigureExtension;
use App\Ui\View\BarChartView;
use App\Ui\View\KpiView;
use App\Ui\View\LineChartView;
use App\Ui\View\NoteView;
use App\Ui\View\PageView;
use App\Ui\View\PeriodView;
use App\Ui\View\TableView;
use App\Ui\View\Words;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Copy with source": a page, or one figure, as plain text to paste into a report, with where each
 * figure was read and the link that proves it again. Only what the page shows: a refused cell and
 * a text that is not shown are left out, so no number travels that the server did not read.
 */
final readonly class PageCitation
{
    public function __construct(
        private FigureExtension $figures,
        private Catalog $catalog,
        private TranslatorInterface $translator,
    ) {
    }

    /** @param ?string $link the address of the whole page, where every figure is read again */
    public function page(PageView $page, ?string $link): string
    {
        $blocks = [];
        if (null === $page->title->problem && '' !== trim($page->title->text)) {
            $blocks[] = trim($page->title->text);
        }
        /** @var array<string, MeasuredProof> $cells one cell per dataset, for the sources */
        $cells = [];
        foreach ($page->components as $component) {
            $block = match (true) {
                $component instanceof KpiView => $this->titled($component->label, $this->lines([$component->figure], $cells)),
                $component instanceof BarChartView => $this->titled($component->title, $this->lines(array_column($component->bars, 'figure'), $cells)),
                $component instanceof TableView => $this->titled($component->title, $this->lines($component->rows, $cells)),
                $component instanceof LineChartView => $this->titled($component->title, $this->lines(array_merge(...array_map(static fn ($line): array => \is_array($line->points) ? $line->points : [], $component->lines)), $cells)),
                $component instanceof NoteView => $this->sentence($component, $cells),
            };
            if ('' !== $block) {
                $blocks[] = $block;
            }
        }
        if ([] === $cells) {
            return '';
        }
        $blocks[] = implode("\n", array_map($this->source(...), array_values($cells)));
        $credits = array_map(static fn (Attribution $credit): string => null === $credit->url ? $credit->text : \sprintf('%s (%s)', $credit->text, $credit->url), $this->figures->credits($page));
        if ([] !== $credits) {
            $blocks[] = implode('; ', $credits).'.';
        }
        if (null !== $link) {
            $blocks[] = $this->translator->trans('Every figure, with its proof: %link%', ['%link%' => $link]);
        }

        return implode("\n\n", $blocks)."\n";
    }

    /** One figure, in one paragraph: the value, what it is, where it was read, its proof. */
    public function figure(MeasuredProof $proof): string
    {
        return \sprintf("%s. %s, %s.\n%s\n%s\n",
            $this->figures->text($proof),
            $this->figures->datasetTitle($proof->fact->dataset),
            $this->figures->caption($proof),
            $this->source($proof),
            $this->translator->trans('Proof: %link%', ['%link%' => $this->figures->proofUrl($proof)]),
        );
    }

    /**
     * @param list<mixed>                    $figures
     * @param array<string, MeasuredProof> $cells
     *
     * @return list<string> "- Spain, 2025: 366,146,835 nights", for the proven figures
     */
    private function lines(array $figures, array &$cells): array
    {
        $lines = [];
        foreach ($figures as $figure) {
            if ($figure instanceof MeasuredProof || $figure instanceof ComputedProof) {
                $lines[] = \sprintf('- %s: %s', $this->figures->caption($figure), $this->figures->text($figure));
                $this->seen($figure, $cells);
            }
        }

        return $lines;
    }

    /** @param list<string> $lines */
    private function titled(Words $title, array $lines): string
    {
        if ([] === $lines) {
            return '';
        }

        return implode("\n", [...(null === $title->problem && '' !== trim($title->text) ? [trim($title->text)] : []), ...$lines]);
    }

    /**
     * A note as one sentence, each figure with its cell. A note with a text that is not shown is
     * left out whole: half a sentence says something else.
     *
     * @param array<string, MeasuredProof> $cells
     */
    private function sentence(NoteView $note, array &$cells): string
    {
        $text = '';
        $figures = [];
        foreach ($note->segments as $segment) {
            if ($segment instanceof Words) {
                if (null !== $segment->problem) {
                    return '';
                }
                $text .= $segment->text;
            } elseif ($segment instanceof PeriodView) {
                if (null !== $segment->problem) {
                    return '';
                }
                $text .= $segment->from === $segment->to ? $this->figures->periodName($segment->from) : $this->figures->periodName($segment->from).' '.$this->translator->trans('to').' '.$this->figures->periodName($segment->to);
            } elseif ($segment instanceof MeasuredProof || $segment instanceof ComputedProof) {
                $text .= \sprintf('%s (%s)', $this->figures->text($segment), $this->figures->caption($segment));
                $figures[] = $segment;
            } else {
                return '';
            }
        }
        foreach ($figures as $figure) {
            $this->seen($figure, $cells);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @param array<string, MeasuredProof> $cells */
    private function seen(MeasuredProof|ComputedProof $figure, array &$cells): void
    {
        foreach ($figure instanceof MeasuredProof ? [$figure] : $figure->operands as $cell) {
            $cells[$cell->fact->dataset] ??= $cell;
        }
    }

    /** "Source: Eurostat, Nights spent in hotels (tour_occ_ninat), read on 2026-11-18." */
    private function source(MeasuredProof $cell): string
    {
        $entry = $this->catalog->get($cell->fact->dataset);

        return $this->translator->trans('Source: %provider%, %title% (%code%), read on %date%.', [
            '%provider%' => ucfirst($this->translator->trans($cell->source->provider, domain: 'providers')),
            '%title%' => $this->figures->datasetTitle($cell->fact->dataset),
            '%code%' => $entry->sourceDataset ?? $cell->fact->dataset,
            '%date%' => $cell->fact->snapshotDate,
        ]);
    }
}
