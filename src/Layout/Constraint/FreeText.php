<?php

declare(strict_types=1);

namespace App\Layout\Constraint;

/**
 * The rule on text that is not written by the server: it carries no number. Used by
 * #[NoDigits] on the layout, by the catalog titles, and wherever a page echoes what it was sent.
 */
final class FreeText
{
    /**
     * Math symbols, currency symbols, and the signs that turn a figure into a rate or a temperature.
     * Arrows and the vertical bar are math symbols too, but only join words: "Paris → Rome".
     */
    private const string SIGNS = '/(?![\x{2190}-\x{21FF}|])[\p{Sm}\p{Sc}%‰‱°]/u';
    /** Invisible characters, and the enclosed letters NFKC leaves as symbols ("🅣🅦🅔🅛🅥🅔"). */
    private const string INVISIBLE = '/[\p{Cf}\p{DI}]/u';
    private const string LETTER_SYMBOLS = '/[\x{1F100}-\x{1F1E5}]/u';
    /** An emoji code that chat hosts draw as a picture: ":one:" becomes a keycap digit. */
    private const string EMOJI_CODE = '/:[a-z0-9_+-]{1,40}:/i';
    /** What hosts turn into a link: a scheme ("https://", "mailto:"), "www.", a mail address. */
    private const string LINK = '/[a-z][a-z0-9+.-]*:\/\/|\bwww\.|[\p{L}\p{N}._%+-]@[\p{L}\p{N}-]+\.\p{L}|\b(?:mailto|xmpp):/iu';

    private static ?\Transliterator $ascii = null;

    public static function problem(string $text): ?FreeTextProblem
    {
        // NFKC turns compatibility forms back into plain ones: "ｔｗｅｌｖｅ" and "𝐭𝐰𝐞𝐥𝐯𝐞" become
        // "twelve", "％" becomes "%". Text that is not valid UTF-8 cannot be checked.
        $nfkc = \Normalizer::normalize($text, \Normalizer::FORM_KC);
        if (false === $nfkc) {
            return FreeTextProblem::Number;
        }

        return match (true) {
            // NFKC turns Roman numerals (Ⅻ) into letters: digits are looked for before it too.
            self::hasDigit($text), self::hasDigit($nfkc) => FreeTextProblem::Number,
            1 === preg_match(self::INVISIBLE, $text) => FreeTextProblem::Invisible,
            1 === preg_match(self::LETTER_SYMBOLS, $text), self::mixesAlphabets($nfkc), 1 === preg_match(self::EMOJI_CODE, $nfkc) => FreeTextProblem::Disguise,
            self::hasNumberWord($nfkc) => FreeTextProblem::Number,
            1 === preg_match(self::LINK, $nfkc) => FreeTextProblem::Link,
            1 === preg_match(self::SIGNS, $nfkc) => FreeTextProblem::Sign,
            default => null,
        };
    }

    /** The longest refused text a page shows struck out. */
    private const int MAX_STRUCK = 160;
    /** What stands for a number that is not shown. */
    public const string BLANK = "\u{2588}\u{2588}";

    /**
     * The text with each number it holds blanked ("up ██ on the year before"): what the model
     * tried, without the number it wrote. Null when blanking its digits is not enough to show it:
     * a number in words, a web address, letters in disguise.
     */
    public static function blanked(string $text): ?string
    {
        $nfkc = \Normalizer::normalize($text, \Normalizer::FORM_KC);
        if (false === $nfkc) {
            return null;
        }
        // A number with what belongs to it: the sign or the currency before, the separators inside,
        // the percent, the degree or the currency after.
        $blanked = preg_replace('/(?:(?<![\p{L}\p{N}])[-\x{2010}-\x{2015}\x{2212}]\s?)?(?:[\p{Sm}\p{Sc}]\s?){0,2}\p{N}+(?:[.,\x{A0}\x{202F}\x{2009} ]\p{N}+)*(?:\s?(?:[%‰‱]|°\s?[CF]?\b|°|\p{Sc}))?/u', self::BLANK, $nfkc);
        if (!\is_string($blanked) || $blanked === $nfkc) {
            return null;
        }
        // A blank that touches a letter is a number written into a word ("f0ur", "3rd", "5k"): the
        // letters around it would still say it. Nothing of such a text is shown.
        if (1 === preg_match('/[\p{L}\p{M}]'.self::BLANK.'|'.self::BLANK.'[\p{L}\p{M}]/u', $blanked)) {
            return null;
        }
        // What is left must be showable both ways: the blanks as spaces, and taken out.
        if (null !== self::problem(str_replace(self::BLANK, ' ', $blanked)) || null !== self::problem(str_replace(self::BLANK, '', $blanked))) {
            return null;
        }
        $blanked = trim((string) preg_replace('/\s+/u', ' ', $blanked));
        if (mb_strlen($blanked) > self::MAX_STRUCK) {
            // Cut between words: half a word could be a number ("ten" of "tenant").
            $cut = mb_substr($blanked, 0, self::MAX_STRUCK);
            $blanked = rtrim(mb_substr($cut, 0, (int) mb_strrpos($cut, ' '))).'…';
        }

        return $blanked;
    }

    /**
     * \p{N} covers digits and numerals as PCRE knows them; ICU also knows the scripts added since
     * (Unicode 16 outlined digits, Garay…) and numerals written with letters (三, 百).
     */
    private static function hasDigit(string $text): bool
    {
        if (0 !== preg_match('/\p{N}/u', $text)) {
            return true;
        }
        foreach (mb_str_split($text) as $char) {
            if (\IntlChar::getNumericValue(mb_ord($char)) !== \IntlChar::NO_NUMERIC_VALUE) {
                return true;
            }
        }

        return false;
    }

    /**
     * A word with Latin letters and letters of another alphabet: "twеlve" with a Cyrillic "е".
     */
    private static function mixesAlphabets(string $text): bool
    {
        preg_match_all('/[\p{L}\p{M}]+/u', $text, $words);
        foreach ($words[0] as $word) {
            if (1 === preg_match('/\p{Latin}/u', $word) && 1 === preg_match('/[^\p{Latin}\p{M}\p{Common}\p{Inherited}]/u', $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each word is first brought to plain letters: accents and marks removed ("twélve", "t̵w̵elve"),
     * then ICU's Latin-ASCII transliteration ("ᴛᴡᴇʟᴠᴇ", "tweɪve"). NumberWords then looks it up,
     * whole, joined ("twentyfive") or as look-alike ("tweIve", "rnillion", Cyrillic "ѕіх").
     */
    private static function hasNumberWord(string $text): bool
    {
        $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_KD);
        $plain = (string) preg_replace('/\p{M}/u', '', false === $decomposed ? $text : $decomposed);
        $plain = (self::$ascii ??= \Transliterator::create('Latin-ASCII') ?? throw new \LogicException('ICU has no Latin-ASCII transliterator.'))->transliterate($plain);

        preg_match_all('/\p{L}+/u', false === $plain ? $text : $plain, $words);
        foreach ($words[0] as $word) {
            if (NumberWords::is($word)) {
                return true;
            }
        }

        return false;
    }
}
