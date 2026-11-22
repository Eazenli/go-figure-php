<?php

declare(strict_types=1);

namespace App\Layout;

use App\Layout\Constraint\PeriodCode;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * A period inside a note, e.g. "2019" or "2019 to 2024": the server writes it.
 */
final readonly class PeriodRef implements Segment
{
    public function __construct(
        public string $kind,
        #[Schema(description: 'Period, e.g. "2019" or "2019-07".')]
        #[PeriodCode]
        public string $from,
        #[Schema(description: 'Same as "from" for a single period.')]
        #[PeriodCode]
        public string $to,
    ) {
    }
}
