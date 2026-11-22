<?php

declare(strict_types=1);

namespace App\Layout;

use App\Layout\Constraint\NoDigits;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

final readonly class Text implements Segment
{
    public function __construct(
        public string $kind,
        #[Schema(description: 'Words only: no digits, no numbers in words ("two", "second", "hundreds"), no signs such as %, no web address. Use a figure or period segment for any number; the server writes each figure with its unit and its place and period.')]
        #[NoDigits]
        public string $text,
    ) {
    }
}
