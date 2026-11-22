<?php

declare(strict_types=1);

namespace App\Layout;

use App\Layout\Constraint\PeriodCode;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * Every period of one dataset in one place, from one period to another (both included).
 */
final readonly class Series
{
    public function __construct(
        #[Schema(description: 'Catalog dataset id.')]
        public string $dataset,
        #[Schema(description: 'Place code, as in the catalog.')]
        public string $geo,
        #[Schema(description: 'First period, e.g. "2019" or "2019-07".')]
        #[PeriodCode]
        public string $from,
        #[Schema(description: 'Last period, same format as "from".')]
        #[PeriodCode]
        public string $to,
    ) {
    }
}
