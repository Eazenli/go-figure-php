<?php

declare(strict_types=1);

namespace App\Layout\Constraint;

/**
 * Why a piece of free text cannot be shown on a page.
 */
enum FreeTextProblem
{
    /** A digit, a numeral, or a number in words. */
    case Number;
    /** A sign that changes what a figure next to it means: %, +, −, currency… */
    case Sign;
    /** An invisible character: a right-to-left override, a zero-width joiner, a variation selector… */
    case Invisible;
    /** Letters in disguise: a Cyrillic "е" inside a Latin word, letters drawn as symbols, an emoji code (":one:"). */
    case Disguise;
    /** A web or mail address: hosts turn it into a link, and the only links of a page are written by the server (proofs, credits). */
    case Link;
}
