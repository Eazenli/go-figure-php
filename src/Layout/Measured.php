<?php

declare(strict_types=1);

namespace App\Layout;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * One cell of the data: a dataset, a place, a period.
 */
final readonly class Measured implements Figure
{
    public function __construct(
        // The enum is only added by the DiscriminatorMap when Measured is reached through
        // Figure; Computed::$operands names Measured directly, so it is declared here too.
        #[Schema(enum: ['measured'])]
        public string $type,
        #[Schema(description: 'Catalog dataset id.')]
        public string $dataset,
        #[Schema(description: 'Place code, as in the catalog.')]
        public string $geo,
        #[Schema(description: 'Period: "2024", "2024-07", or "M09" for a climate normal.')]
        public string $time,
    ) {
    }
}
