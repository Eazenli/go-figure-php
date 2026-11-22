<?php

declare(strict_types=1);

namespace App\Import;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/** The code a dimension of the dataset is frozen to: unit = NR, c_resid = TOTAL. */
final class DimensionChoice
{
    public function __construct(
        #[Schema(description: 'A dimension of the dataset, as Eurostat writes it: unit, c_resid, nace_r2. Never geo or time.')]
        public string $dimension = '',
        #[Schema(description: 'One of the codes of that dimension, as Eurostat writes it: NR, TOTAL, I551-I553.')]
        public string $code = '',
    ) {
    }
}
