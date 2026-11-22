<?php

declare(strict_types=1);

namespace App\Layout\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * A period as the clean table writes it: "2024", "2024-07", or "M09" for a climate normal.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class PeriodCode extends Constraint
{
    public const string PATTERN = '/^(\d{4}|\d{4}-(0[1-9]|1[0-2])|M(0[1-9]|1[0-2]))$/D';

    public string $message = 'This period is not a year, a month or a month of the year.';

    public function __construct(?array $groups = [Render::GROUP], mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }
}
