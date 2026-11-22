<?php

declare(strict_types=1);

namespace App\Layout;

use App\Layout\Constraint\NoDigits;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * One headline figure.
 */
final readonly class KpiTile implements Component
{
    public function __construct(
        public string $kind,
        #[Schema(description: 'Short label, no digits and no numbers in words.')]
        #[NoDigits]
        public string $label,
        public Figure $figure,
    ) {
    }
}
