<?php

declare(strict_types=1);

namespace App\Layout;

use App\Layout\Constraint\NoDigits;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * One line per series; the server reads every point of each series from the data.
 */
final readonly class LineChart implements Component
{
    /**
     * @param list<Series> $series
     */
    public function __construct(
        public string $kind,
        #[Schema(description: 'Chart title, no digits and no numbers in words.')]
        #[NoDigits]
        public string $title,
        public array $series,
    ) {
    }
}
