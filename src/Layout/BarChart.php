<?php

declare(strict_types=1);

namespace App\Layout;

use App\Layout\Constraint\NoDigits;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * One bar per figure. The server labels each bar from its cell (place, period).
 */
final readonly class BarChart implements Component
{
    /**
     * @param list<Figure> $bars
     */
    public function __construct(
        public string $kind,
        #[Schema(description: 'Chart title, no digits and no numbers in words.')]
        #[NoDigits]
        public string $title,
        public array $bars,
    ) {
    }
}
