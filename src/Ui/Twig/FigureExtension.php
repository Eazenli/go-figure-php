<?php

declare(strict_types=1);

namespace App\Ui\Twig;

use App\Import\Importer;
use App\Import\ImportStore;
use App\Catalog\Attribution;
use App\Catalog\Catalog;
use App\Catalog\Places;
use App\Composer\PageComposer;
use App\Http\Language;
use App\Layout\Constraint\FreeText;
use App\Layout\Constraint\PeriodCode;
use App\Layout\Measured;
use App\Layout\Op;
use App\Layout\Series;
use App\Proof\ComputedProof;
use App\Proof\MeasuredProof;
use App\Proof\Refusal;
use App\Ui\View\BarChartView;
use App\Ui\View\KpiView;
use App\Ui\View\LineChartView;
use App\Ui\View\NoteView;
use App\Ui\View\PageView;
use App\Ui\View\PeriodView;
use App\Ui\View\TableView;
use App\Ui\View\Words;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

/**
 * The |figure filter: the only way a value reaches a page. It formats the value on the server and
 * links it to its proof.
 */
final class FigureExtension extends AbstractExtension implements LocaleAwareInterface
{
    /** Decimals shown per unit code; the value itself is never rounded. */
    private const array DECIMALS = ['NR' => 0, 'PAS' => 0, 'THS_PAS' => 0, 'MIO_PKM' => 0, 'P_THAB' => 0, 'CP_EUR_HAB' => 0, 'KG_HAB' => 0, 'RCH_A_AVG' => 1, 'mm' => 0, 'PC' => 1, '%' => 1, 'degC' => 1, 'PLI_EU27_2020' => 1];
    /** A price level index where the EU average is 100 reads as a share of it: "75.5% of the EU average". */
    private const array SUFFIXES = ['PC' => '%', 'RCH_A_AVG' => '%', '%' => '%', 'degC' => ' °C', 'mm' => ' mm', 'PLI_EU27_2020' => '%'];
    /** Eurostat's flags, a letter each: a cell may carry several ("be": break in series, estimated). */
    public const array FLAGS = [
        'b' => 'break in series', 'c' => 'confidential', 'd' => 'definition differs', 'e' => 'estimated',
        'f' => 'forecast', 'n' => 'not significant', 'p' => 'provisional', 'r' => 'revised',
        's' => 'Eurostat estimate', 'u' => 'low reliability', 'z' => 'not applicable',
        'i' => 'imputed', 'm' => 'missing, cannot exist',
    ];

    /** The language of the page: numbers are written its way ("457,644,262", "457 644 262"). */
    private string $locale = 'en';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly Catalog $catalog,
        private readonly Places $places,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('figure', $this->figure(...), ['is_safe' => ['html']]),
            new TwigFilter('figure_number', $this->number(...)),
            new TwigFilter('figure_unit', $this->unit(...)),
            new TwigFilter('figure_text', $this->text(...)),
            new TwigFilter('requested', $this->requested(...)),
            new TwigFilter('proof_url', $this->proofUrl(...)),
            new TwigFilter('flag_label', $this->flagLabel(...)),
            new TwigFilter('period_name', $this->periodName(...)),
            new TwigFilter('dataset_title', $this->datasetTitle(...)),
            new TwigFilter('dataset_scope', $this->datasetScope(...)),
            new TwigFilter('preg_words', static fn (string $text): string => trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L} ]+/u', ' ', $text)))),
            new TwigFilter('cell_caption', $this->caption(...)),
            new TwigFilter('place_name', $this->places->name(...)),
            new TwigFilter('place_scale', $this->places->scale(...)),
            new TwigFilter('after_unit', $this->afterUnit(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('page_credits', $this->credits(...)),
            new TwigFunction('block_tally', $this->blockTally(...)),
            new TwigFunction('block_scope', $this->blockScope(...)),
        ];
    }

    /**
     * What the sources of a page's proven figures ask to show next to them (Open-Meteo's link,
     * Copernicus), once each, in the order of the page.
     *
     * @return list<Attribution>
     */
    public function credits(PageView $page): array
    {
        $credits = [];
        foreach ($page->parts() as $part) {
            // A computed figure owes what each of its cells owes.
            foreach ($part instanceof MeasuredProof ? [$part] : ($part instanceof ComputedProof ? $part->operands : []) as $cell) {
                foreach ($this->catalog->get($cell->fact->dataset)->attributions ?? [] as $attribution) {
                    $credits[$attribution->text] ??= $attribution;
                }
            }
        }

        return array_values($credits);
    }

    public function getTests(): array
    {
        return [
            new TwigTest('proof', static fn (mixed $v): bool => $v instanceof MeasuredProof || $v instanceof ComputedProof),
            new TwigTest('computed', static fn (mixed $v): bool => $v instanceof ComputedProof),
            new TwigTest('refusal', static fn (mixed $v): bool => $v instanceof Refusal),
            new TwigTest('words', static fn (mixed $v): bool => $v instanceof Words),
            new TwigTest('period', static fn (mixed $v): bool => $v instanceof PeriodView),
            new TwigTest('followed_by_its_unit', $this->followedByItsUnit(...)),
        ];
    }

    /**
     * What a flag of the source means, letter by letter, in English ("break in series, estimated"):
     * the Markdown of MCP. A letter we do not know says it is a flag of the source.
     *
     * @return list<string>
     */
    public static function flagWords(string $flag): array
    {
        return array_values(array_unique(array_map(
            static fn (string $letter): string => self::FLAGS[$letter] ?? 'source flag',
            '' === $flag ? [] : mb_str_split($flag),
        )));
    }

    /**
     * A month of the year (a climate normal, "M09") by its name in the language of the page
     * ("September", "septembre"), a month of one year ("2025-09") with its year ("September 2025");
     * any other period as the data writes it ("2024").
     */
    public function periodName(string $period): string
    {
        if (1 === preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $period, $month)) {
            return PageComposer::monthName($month[2], $this->locale).' '.$month[1];
        }

        return 1 === preg_match('/^M(0[1-9]|1[0-2])$/D', $period, $month) ? PageComposer::monthName($month[1], $this->locale) : $period;
    }

    /** The same in the language of the page. */
    public function flagLabel(string $flag): string
    {
        return implode(', ', array_map(fn (string $words): string => $this->translator->trans($words), self::flagWords($flag)));
    }

    /**
     * The flags of a figure: its own, or every flag of the cells it is computed from (a change
     * across a break in series says so).
     */
    public static function flags(MeasuredProof|ComputedProof $proof): string
    {
        $letters = implode('', array_map(static fn (MeasuredProof $p): string => $p->fact->status, $proof instanceof MeasuredProof ? [$proof] : $proof->operands));

        return implode('', array_unique(mb_str_split($letters)));
    }

    /** Whether a figure is a change between two values that are themselves percentages: in points. */
    private static function inPoints(MeasuredProof|ComputedProof $proof): bool
    {
        return $proof instanceof ComputedProof && Op::Change === $proof->op && '%' === (self::SUFFIXES[$proof->unit] ?? '');
    }

    public function figure(MeasuredProof|ComputedProof $proof): string
    {
        $flag = self::flags($proof);
        $unit = $this->unit($proof);

        // A very large value is written twice by the server: in words of scale, to be read at a
        // glance ("30.77 trillion"), and in full, as the source gives it.
        $round = $this->inWords($proof);

        return \sprintf(
            '<a class="gf-figure" href="%s" title="%s">%s</a>%s%s%s',
            self::e($this->proofUrl($proof)),
            self::e($this->translator->trans('Proof: %cell%', ['%cell%' => $proof instanceof MeasuredProof ? \sprintf('%s, %s', $this->datasetTitle($proof->fact->dataset), $this->caption($proof)) : $this->caption($proof)])),
            self::e($round ?? $this->number($proof)),
            '' === $flag ? '' : \sprintf('<sup class="gf-flag" title="%s">%s</sup>', self::e($this->flagLabel($flag)), self::e($flag)),
            '' === $unit ? '' : \sprintf(' <span class="gf-unit">%s</span>', self::e($unit)),
            null === $round ? '' : \sprintf(' <span class="gf-exact">(%s)</span>', self::e($this->number($proof))),
        );
    }

    /**
     * A value of a billion or more, rounded by the server and said with its scale: "30.77 trillion",
     * "41,26 billions" in French (where a "billion" is a million millions). Null for any other value,
     * a percentage or a rank: they are short enough as they are.
     */
    private function inWords(MeasuredProof|ComputedProof $proof): ?string
    {
        if ($proof instanceof ComputedProof && \in_array($proof->op, [Op::ChangePct, Op::Share, Op::Rank], true)) {
            return null;
        }
        $unit = $proof instanceof MeasuredProof ? $proof->fact->unit : $proof->unit;
        $size = abs($proof->value);
        if ($size < 1e9 || isset(self::SUFFIXES[$unit])) {
            return null;
        }
        // A unit that already says a scale ("million euro") is left as it is: no "billion million".
        if (1 === preg_match('/^(hundred|thousand|million|billion|trillion)\b/i', $this->unit($proof))) {
            return null;
        }
        // The scale is chosen on the rounded value: 999,996,000,000 is "1 trillion", not "1,000 billion".
        [$divisor, $word] = round($size / 1e9, 2) >= 1000 ? [1e12, 'trillion'] : [1e9, 'billion'];
        $shown = round($proof->value / $divisor, 2);
        $formatter = new \NumberFormatter($this->locale, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
        if ($proof instanceof ComputedProof && $proof->value > 0) {
            $formatter->setTextAttribute(\NumberFormatter::POSITIVE_PREFIX, '+'); // a change says its direction
        }

        return $formatter->format($shown).' '.$this->translator->trans($word, ['%count%' => abs($shown) >= 2 ? 2 : 1], 'scales');
    }

    /**
     * The unit shown after a value, written in the catalog (unit.label), scale included: "thousand
     * passengers". Empty when the number already carries it ("14.4%", "31.2 °C"). A value is never
     * shown without its unit: the model cannot write one, so a misread scale cannot creep in.
     */
    public function unit(MeasuredProof|ComputedProof $proof): string
    {
        if ($proof instanceof ComputedProof) {
            return match (true) {
                Op::ChangePct === $proof->op, Op::Share === $proof->op => '',
                Op::Rank === $proof->op => $this->translator->trans('by rank, highest first'),
                // Between two percentages, a change is in points, never "percent".
                self::inPoints($proof) => trim($this->translator->trans('percentage points').' '.$this->unit($proof->operands[0])),
                // A change is in the unit of its cells.
                default => $this->unit($proof->operands[0]),
            };
        }
        $label = $this->label($proof);

        // The catalog writes units in English; the page's language may have its own word (units domain).
        return '' === $label ? '' : $this->translator->trans($label, domain: 'units');
    }

    /** The catalog's unit label, in English; empty when the number already carries it. */
    private function label(MeasuredProof $proof): string
    {
        $label = trim($this->catalog->get($proof->fact->dataset)->unitLabel ?? $proof->fact->unit);
        $suffix = trim(self::SUFFIXES[$proof->fact->unit] ?? '');

        return '' !== $suffix && 0 === strcasecmp($label, $suffix) ? '' : $label;
    }

    /**
     * The words a model may write for the unit right after a figure: the catalog's, in English, and
     * the same unit in every language of the site (a model answers in the language of the question).
     *
     * @return list<string>
     */
    private function unitWords(MeasuredProof $proof): array
    {
        $label = $this->label($proof);
        if ('' === $label) {
            return [];
        }
        // A model asked in French writes "nuitées", in English "nights": whatever the page's language.
        $words = [$label];
        foreach (Language::LANGUAGES as $language) {
            $words[] = $this->translator->trans($label, domain: 'units', locale: $language);
        }

        return array_values(array_unique($words));
    }

    /** The cell whose unit a figure is written in: its own, or the cells' of a plain change. */
    private static function unitOf(MeasuredProof|ComputedProof $proof): ?MeasuredProof
    {
        return match (true) {
            $proof instanceof MeasuredProof => $proof,
            Op::Change === $proof->op && !self::inPoints($proof) => $proof->operands[0],
            default => null,
        };
    }

    /**
     * Whether the words right after a figure in a note start with its unit: "457,644,262 nights"
     * then " nights in". Words that break a rule never count.
     */
    public function followedByItsUnit(MeasuredProof|ComputedProof $proof, mixed $next): bool
    {
        $proof = self::unitOf($proof);

        return null !== $proof && $next instanceof Words && null === $next->problem && null !== $this->unitPattern($proof)
            && 1 === preg_match($this->unitPattern($proof), $next->text);
    }

    /** The unit right at the start of the words that follow a figure, in any of its words. */
    private function unitPattern(MeasuredProof $proof): ?string
    {
        $words = $this->unitWords($proof);

        return [] === $words ? null : '/^\s*(?:'.implode('|', array_map(static fn (string $w): string => preg_quote($w, '/'), $words)).')(?![\p{L}\p{N}])/iu';
    }

    /**
     * The words that follow a figure in a note, without the unit the server already wrote after it:
     * "457,644,262 nights" then " nights in" reads "457,644,262 nights (France, 2024) in". The
     * server's unit always stays; only the model's repetition goes.
     */
    public function afterUnit(Words $words, mixed $previous): Words
    {
        $cell = $previous instanceof MeasuredProof || $previous instanceof ComputedProof ? self::unitOf($previous) : null;
        if (null === $cell || !$this->followedByItsUnit($cell, $words)) {
            return $words;
        }

        return new Words((string) preg_replace((string) $this->unitPattern($cell), '', $words->text), $words->problem, $words->struck);
    }

    /**
     * Which cells a figure is, written by the server: "France, 2024"; for a calculation, the cells it
     * reads ("Portugal, 2019 to 2024"; "Spain, 2024, hotels in all accommodation"; "Spain, 2024,
     * among France, Italy and Spain").
     */
    public function caption(MeasuredProof|ComputedProof $proof): string
    {
        $cell = fn (MeasuredProof $p): string => \sprintf('%s, %s', $this->places->name($p->fact->geo), $this->periodName($p->fact->time));
        if ($proof instanceof MeasuredProof) {
            return $cell($proof);
        }
        [$a, $b] = $proof->operands;

        return match ($proof->op) {
            Op::Change, Op::ChangePct => $a->fact->geo === $b->fact->geo
                ? $this->translator->trans('%cell% to %period%', ['%cell%' => $cell($a), '%period%' => $this->periodName($b->fact->time)])
                : $this->translator->trans('%from% to %to%', ['%from%' => $cell($a), '%to%' => $cell($b)]),
            Op::Share => $this->translator->trans('%cell%: %part% in %whole%', [
                '%cell%' => $cell($a),
                '%part%' => $this->catalog->get($a->fact->dataset)?->titleIn($this->locale) ?? $a->fact->dataset,
                '%whole%' => $this->catalog->get($b->fact->dataset)?->titleIn($this->locale) ?? $b->fact->dataset,
            ]),
            Op::Rank => $this->translator->trans('%cell%, among %places%', ['%cell%' => $cell($a), '%places%' => implode(', ', array_map(fn (MeasuredProof $p): string => $this->places->name($p->fact->geo), $proof->operands))]),
        };
    }

    /** The value and its unit as plain text, for places where a link cannot go (an SVG title). */
    public function text(MeasuredProof|ComputedProof $proof): string
    {
        return trim($this->number($proof).' '.$this->unit($proof));
    }

    /** The absolute, stateless link to a proof: the same in HTML, in Markdown and in MCP results. */
    public function proofUrl(MeasuredProof|ComputedProof $proof): string
    {
        return $this->urls->generate('proof', ['token' => $proof->token()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** The formatted value alone, for places where a link cannot go (an SVG title, a text fallback). */
    public function number(MeasuredProof|ComputedProof $proof): string
    {
        if ($proof instanceof ComputedProof && Op::Rank === $proof->op) {
            // "2 of 5": the place and how many were ranked, both the server's own count.
            return $this->translator->trans('%rank% of %count%', ['%rank%' => (string) (int) $proof->value, '%count%' => (string) \count($proof->operands)]);
        }
        $unit = $proof instanceof MeasuredProof ? $proof->fact->unit : $proof->unit;
        $formatter = new \NumberFormatter($this->locale, \NumberFormatter::DECIMAL);
        $value = $proof->value;
        if ($proof instanceof ComputedProof && \in_array($proof->op, [Op::Change, Op::ChangePct], true)) {
            // A change says its direction ("+12.3%"), except when it rounds to nothing.
            $shown = round($value, self::DECIMALS[$unit] ?? 1);
            if (0.0 === $shown || -0.0 === $shown) {
                $value = 0.0;
            } else {
                $formatter->setTextAttribute(\NumberFormatter::POSITIVE_PREFIX, '+');
            }
        }
        $decimals = self::DECIMALS[$unit] ?? null;
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals ?? 0);
        // Without a unit code (an import whose source gives none), a small value keeps the digits
        // that tell it from its neighbours: "0.04 hectares per person", not "0".
        $small = Importer::NO_UNIT === $unit && abs($value) < 10 ? (abs($value) < 1 ? 3 : 2) : 1;
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals ?? $small);

        $suffix = self::inPoints($proof) ? '' : (self::SUFFIXES[$unit] ?? '');
        // French puts a narrow space before the percent sign: "14,4 %".
        if ('%' === $suffix && 'fr' === $this->locale) {
            $suffix = "\u{202F}%";
        }

        return $formatter->format($value).$suffix;
    }

    /**
     * What a refused figure asked for, in plain words. A refusal card never shows a value, so only
     * strings that cannot carry one are echoed: a code that passes the free-text rule ("UK", not
     * "NINETY"), or a place, dataset or period the catalog knows ("EU27_2020", "2024").
     * "457644262" or "4576" are not echoed.
     */
    public function requested(Refusal $refusal, bool $codes = false): string
    {
        $r = $refusal->requested;
        // On a page, what the data knows is shown by its name; what it does not know stays as the
        // model wrote it. A model reading the Markdown of MCP gets the codes, to correct its request.
        $dataset = fn (string $s): string => !$codes && null !== $this->catalog->get($s) ? $this->datasetTitle($s) : $this->code($s, null !== $this->catalog->get($s));
        $place = fn (string $s): string => !$codes && $this->catalog->knowsPlace($s) ? $this->places->name($s) : $this->code($s, $this->catalog->knowsPlace($s));
        $period = fn (string $s): string => match (true) {
            $this->catalog->knowsPeriod($s) => $codes ? $s : $this->periodName($s),
            1 === preg_match(PeriodCode::PATTERN, $s) => $this->translator->trans('(a period outside the data)'),
            default => $this->translator->trans('(not a code)'),
        };

        return match (true) {
            $r instanceof Measured => implode(', ', [$dataset($r->dataset), $place($r->geo), $period($r->time)]),
            $r instanceof Series => \sprintf('%s, %s, %s', $dataset($r->dataset), $place($r->geo), $this->translator->trans('%from% to %to%', ['%from%' => $period($r->from), '%to%' => $period($r->to)])),
            default => $this->translator->trans('a calculation'),
        };
    }

    /** The title of a dataset in the language of the page; its code when the catalog has none. */
    /**
     * What a block of a page proves: how many of its figures the server read, how many it refused,
     * and from which sources, by their names, in the order of the block.
     *
     * @return array{proven: int, refused: int, sources: list<string>}
     */
    public function blockTally(KpiView|BarChartView|LineChartView|TableView|NoteView $block): array
    {
        $tally = ['proven' => 0, 'refused' => 0, 'sources' => []];
        foreach (PageView::partsOf($block) as $part) {
            if ($part instanceof Refusal) {
                ++$tally['refused'];
            } elseif ($part instanceof MeasuredProof || $part instanceof ComputedProof) {
                ++$tally['proven'];
                foreach ($part instanceof MeasuredProof ? [$part] : $part->operands as $cell) {
                    $tally['sources'][$this->translator->trans($cell->source->provider, domain: 'providers')] = true;
                }
            }
        }
        $tally['sources'] = array_map(strval(...), array_keys($tally['sources']));

        return $tally;
    }

    /**
     * What the figures of a block count, in one sentence of the catalog: the first sentence of the
     * scope of its dataset ("hotels and similar accommodation", not all tourist accommodation).
     * Nothing for a block that mixes datasets, or proves no figure: the proof says it for each.
     */
    public function blockScope(KpiView|BarChartView|LineChartView|TableView|NoteView $block): string
    {
        $datasets = [];
        foreach (PageView::partsOf($block) as $part) {
            foreach (match (true) {
                $part instanceof MeasuredProof => [$part],
                $part instanceof ComputedProof => $part->operands,
                default => [],
            } as $cell) {
                $datasets[$cell->fact->dataset] = true;
            }
        }
        if (1 !== \count($datasets)) {
            return '';
        }
        $dataset = (string) array_key_first($datasets);
        $scope = trim($this->datasetScope($dataset));
        // The scope of a dataset added on this machine was not written by us (the note of the World
        // Bank, the words of a visitor): on a page only if it holds no number. The proof shows it whole.
        if (ImportStore::owns($dataset) && null !== FreeText::problem($scope)) {
            return '';
        }

        return trim(preg_split('/(?<=[.!?])\s+(?=\p{Lu})/u', $scope, 2)[0] ?? $scope);
    }

    public function datasetTitle(string $dataset): string
    {
        return $this->catalog->get($dataset)?->titleIn($this->locale) ?? $dataset;
    }

    /** What the dataset measures, in the catalog's own words; nothing for a dataset it does not know. */
    public function datasetScope(string $dataset): string
    {
        return $this->catalog->get($dataset)?->scopeIn($this->locale) ?? '';
    }

    private function code(string $s, bool $known): string
    {
        return $known || (1 === preg_match('/^[A-Za-z_-]{1,40}$/D', $s) && null === FreeText::problem($s)) ? $s : $this->translator->trans('(not a code)');
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
