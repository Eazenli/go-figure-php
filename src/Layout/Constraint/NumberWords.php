<?php

declare(strict_types=1);

namespace App\Layout\Constraint;

/**
 * Numbers written in words, in English and in French: "ninety-nine", "the second busiest",
 * "hundreds of", "quatre-vingt-dix", "douzième". Both, whatever the language of the interface: the
 * model may write in either.
 *
 * The vocabulary is not a list typed by hand: it is every word ICU writes for a number (CLDR's
 * spell-out rules, the ones that turn 99 into "ninety-nine"), read the other way. Every number in
 * words is made of the words ICU writes for 0 to 99 and for the scales, as cardinals and as
 * ordinals. Their plurals are added ("hundreds", "the twenties", "sixes").
 *
 * A word is a number when it is one of these, when it is made of several of them or ends in
 * "fold" ("twentyfive", "tenfold"), or when Unicode lists it as confusable with one of them
 * ("tweIve" with a capital I, "rnillion", a Cyrillic "ѕіх"; Unicode TR39, through ICU).
 *
 * The words ICU writes for 1 are let through, as common as they are harmless ("one", "first",
 * "un", "une", "premier", "première"), and so is "et", which only joins French numbers
 * ("vingt-et-un"). A few words that count without being numbers are added (EXTRA). French words
 * are looked up without their accents, as the text is ("deuxieme"). A French word that is also a
 * word ("neuf", new) is refused all the same: the page then hides the sentence, never shows a
 * number.
 */
final class NumberWords
{
    /** The languages and ICU's rule sets for their cardinals and ordinals. */
    private const array RULESETS = [
        'en' => ['%spellout-cardinal', '%spellout-ordinal'],
        'fr' => ['%spellout-cardinal-masculine', '%spellout-cardinal-feminine', '%spellout-ordinal-masculine', '%spellout-ordinal-feminine'],
    ];
    /** Words ICU writes inside a number that are not numbers themselves. */
    private const array JOINERS = ['et'];
    private const array SCALES = [100, 1000, 1e6, 1e9, 1e12, 1e15, 1e18, 1e21];
    /**
     * Words that count, though ICU writes no number with them alone: "per cent" ends in "cent",
     * and the short forms of scales and rates ("mn", "bn", "pc").
     */
    private const array EXTRA = [
        'dozen', 'dozens', 'twice', 'thrice', 'percent', 'cent', 'cents', 'fourscore', 'nought', 'noughts', 'nil',
        'mn', 'bn', 'mln', 'bln', 'mio', 'milliard', 'milliards', 'lakh', 'lakhs', 'crore', 'crores',
        'pc', 'pct', 'bps', 'mille',
        // French: "une dizaine", "des centaines", "des milliers", "pourcent".
        'dizaine', 'dizaines', 'douzaine', 'douzaines', 'quinzaine', 'quinzaines', 'vingtaine', 'vingtaines',
        'trentaine', 'trentaines', 'quarantaine', 'quarantaines', 'cinquantaine', 'cinquantaines',
        'soixantaine', 'soixantaines', 'centaine', 'centaines', 'millier', 'milliers', 'pourcent', 'pourcents', 'mds', 'mrd',
        // Belgian and Swiss French: 70, 80, 90, their ordinals and approximations.
        'septante', 'octante', 'huitante', 'nonante', 'septantieme', 'octantieme', 'huitantieme', 'nonantieme',
        'septantaine', 'septantaines', 'huitantaine', 'huitantaines', 'nonantaine', 'nonantaines', 'huitaine', 'huitaines',
        // French words ICU does not write for a number: "la seconde ville", "un tiers".
        'seconde', 'secondes', 'tiers',
    ];
    /**
     * Multipliers, as "tenfold", in every form: "quadrupled", "décuplée", "centuplés"; and the verb
     * forms of triple ("tripled", "triplé"). "Triple" alone stays, as "double" does: a triple room.
     */
    private const string MULTIPLIER = '/^(?:(?:quadrupl|quintupl|sextupl|septupl|octupl|decupl|centupl)\p{L}*|(?:tripl|trebl)(?:ed|es|ees?|ing|er|ent|ant|s))$/u';
    private const string FOLD = 'fold';

    /** @var array{words: array<string, true>, parts: array<string, int>, byLength: array<int, list<string>>, allowed: array<string, true>}|null */
    private static ?array $vocabulary = null;
    private static ?\Spoofchecker $confusables = null;
    /** @var array<string, bool> words already looked at; a page repeats its words */
    private static array $seen = [];
    private const int SEEN = 5000;

    /**
     * @param string $word letters only, in any case
     */
    public static function is(string $word): bool
    {
        if (\count(self::$seen) >= self::SEEN) {
            self::$seen = [];
        }

        return self::$seen[$word] ??= self::lookUp($word);
    }

    private static function lookUp(string $word): bool
    {
        $vocabulary = self::vocabulary();
        $lower = mb_strtolower($word);
        if (isset($vocabulary['words'][$lower]) || 1 === preg_match(self::MULTIPLIER, $lower) || self::isJoined($lower, $vocabulary['parts'])) {
            return true;
        }

        // A look-alike keeps the length of the word it imitates, give or take a letter or two
        // ("rn" for "m").
        $length = mb_strlen($word);
        self::$confusables ??= new \Spoofchecker();
        for ($candidate = max(1, $length - 2); $candidate <= $length + 2; ++$candidate) {
            foreach ($vocabulary['byLength'][$candidate] ?? [] as $number) {
                if (self::$confusables->areConfusable($word, $number)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Several number words written as one ("twentyfive", "ninetynine", "vingtetun", "deuxcents"), or
     * number words followed by "fold" ("tenfold", "hundredfold").
     *
     * @param array<string, int> $parts
     */
    private static function isJoined(string $word, array $parts): bool
    {
        if (str_ends_with($word, self::FOLD) && \strlen($word) > \strlen(self::FOLD)) {
            $times = substr($word, 0, -\strlen(self::FOLD));

            // "unfold" is not "one-fold": a word for 1 is let through here as elsewhere.
            return !isset(self::vocabulary()['allowed'][$times]) && self::count($times, $parts) >= 1;
        }

        return self::count($word, $parts) >= 2;
    }

    /**
     * How many number words the whole of $word splits into, or 0 if it does not. A joiner ("et")
     * may sit between them without counting as one.
     *
     * @param array<string, int> $parts each part and what it counts: 1 for a number word, 0 for a joiner
     */
    private static function count(string $word, array $parts): int
    {
        $length = \strlen($word);
        // $best[$i]: the fewest number words that make up the first $i letters, or null.
        $best = [0 => 0];
        for ($end = 1; $end <= $length; ++$end) {
            for ($start = max(0, $end - 12); $start < $end; ++$start) {
                $part = substr($word, $start, $end - $start);
                if (isset($best[$start]) && isset($parts[$part])) {
                    $best[$end] = min($best[$end] ?? \PHP_INT_MAX, $best[$start] + $parts[$part]);
                }
            }
        }

        return $best[$length] ?? 0;
    }

    /**
     * @return array{words: array<string, true>, parts: array<string, int>, byLength: array<int, list<string>>, allowed: array<string, true>}
     */
    private static function vocabulary(): array
    {
        if (null !== self::$vocabulary) {
            return self::$vocabulary;
        }

        $parts = $allowed = [];
        foreach (self::RULESETS as $locale => $rulesets) {
            $spellout = new \NumberFormatter($locale, \NumberFormatter::SPELLOUT);
            foreach ($rulesets as $ruleset) {
                $spellout->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, $ruleset);
                foreach ([...range(0, 99), ...self::SCALES] as $number) {
                    foreach (self::split((string) $spellout->format($number)) as $word) {
                        $parts[$word] = true;
                    }
                }
                $allowed = [...$allowed, ...self::split((string) $spellout->format(1))];
            }
        }
        foreach (self::JOINERS as $word) {
            unset($parts[$word]);
        }
        // What a joined word may be made of: number words, their plurals ("deuxcents", "troismillions"),
        // and the joiners, which do not count ("vingtetun" is two number words).
        $joined = [];
        foreach (array_keys($parts) as $word) {
            $joined[$word] = $joined[$word.'s'] = 1;
        }
        foreach (self::JOINERS as $word) {
            $joined[$word] = 0;
        }

        $words = $parts;
        foreach (array_keys($parts) as $word) {
            $words[$word.'s'] = $words[$word.'es'] = true;
            if (str_ends_with($word, 'y')) {
                $words[substr($word, 0, -1).'ies'] = true;
            }
        }
        foreach ($allowed as $word) {
            unset($words[$word], $words[$word.'s'], $words[$word.'es']);
        }
        foreach (self::EXTRA as $word) {
            $words[$word] = true;
        }

        $byLength = [];
        foreach (array_keys($words) as $word) {
            $byLength[\strlen($word)][] = $word;
        }

        return self::$vocabulary = ['words' => $words, 'parts' => $joined, 'byLength' => $byLength, 'allowed' => array_fill_keys($allowed, true)];
    }

    /**
     * @return list<string>
     */
    private static function split(string $spelled): array
    {
        // Without accents, as the text is looked up ("deuxième" is "deuxieme").
        $decomposed = \Normalizer::normalize($spelled, \Normalizer::FORM_KD);
        $plain = (string) preg_replace('/\p{M}/u', '', false === $decomposed ? $spelled : $decomposed);

        return preg_split('/[^\p{L}]+/u', mb_strtolower($plain), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
