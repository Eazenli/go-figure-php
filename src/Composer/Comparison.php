<?php

declare(strict_types=1);

namespace App\Composer;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Destinations side by side for one month: every indicator of the catalog, for a few places.
 */
final class Comparison
{
    /** @var list<string> */
    #[Assert\Count(min: 2, max: Composition::MAX_PLACES, minMessage: 'Choose at least two places.', maxMessage: 'Choose at most {{ limit }} places.')]
    #[Assert\Unique(message: 'Choose each place once.')]
    public array $places = [];

    /** The month, "01" to "12". */
    #[Assert\NotBlank]
    #[Assert\Regex('/^(0[1-9]|1[0-2])$/D')]
    public ?string $month = null;
}
