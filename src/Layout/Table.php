<?php

declare(strict_types=1);

namespace App\Layout;

use App\Layout\Constraint\NoDigits;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * One row per figure. The server writes the place, dataset and period columns from each cell.
 */
final readonly class Table implements Component
{
    /**
     * @param list<Figure> $rows
     */
    public function __construct(
        public string $kind,
        #[Schema(description: 'Table title, no digits and no numbers in words.')]
        #[NoDigits]
        public string $title,
        public array $rows,
    ) {
    }
}
