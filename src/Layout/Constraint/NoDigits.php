<?php

declare(strict_types=1);

namespace App\Layout\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Text written by the model carries no number: values go through figures, years
 * through periods. See FreeText for the rule; refused, after NFKC normalization:
 * - digits and numerals of any script (7, ٣, ３, ½, Ⅻ, 三);
 * - numbers in English or French words, whole, joined, accented or look-alike ("ninety-nine", "the second",
 *   "hundreds", "twentyfive", "tenfold", "tweIve"): see NumberWords;
 * - signs that change what a figure next to them means (%, +, −, <, =, currency, °);
 * - invisible characters: a right-to-left override before a figure would show "457,644,262" as
 *   "262,446,754", still linked to the proof of the first;
 * - letters in disguise: "twеlve" with a Cyrillic "е", letters drawn as symbols.
 *
 * A custom constraint on purpose: Symfony AI turns the built-in ones (Regex…) into JSON Schema,
 * which would change the layout schema sent to the providers.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class NoDigits extends Constraint
{
    public string $message = 'Unsupported: the model wrote this number itself.';
    public string $signMessage = 'Unsupported: the model wrote this number sign itself.';
    public string $formatMessage = 'Refused: invisible formatting characters are not allowed.';
    public string $alphabetMessage = 'Refused: disguised letters are not allowed.';
    public string $linkMessage = 'Refused: web addresses are not allowed.';

    /**
     * @param string|null $message one message for every case, e.g. for a catalog title
     */
    public function __construct(?array $groups = [Render::GROUP], mixed $payload = null, ?string $message = null)
    {
        parent::__construct(null, $groups, $payload);
        if (null !== $message) {
            $this->message = $this->signMessage = $this->formatMessage = $this->alphabetMessage = $this->linkMessage = $message;
        }
    }
}
