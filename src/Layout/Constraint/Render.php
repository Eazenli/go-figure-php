<?php

declare(strict_types=1);

namespace App\Layout\Constraint;

/**
 * The validation group of the layout rules.
 *
 * Symfony AI validates the final structured output with the default group and throws on the first
 * violation. Our rules live in their own group, so that a digit in a note marks that note
 * "unsourced" on the page instead of failing the whole response.
 */
final class Render
{
    public const string GROUP = 'render';
}
