<?php

declare(strict_types=1);

namespace App\Ui\View;

/**
 * Words written by the model. When they break a layout rule, the page shows the problem instead,
 * next to the words struck out with their number blanked: the attempt, never the number.
 */
final readonly class Words
{
    public function __construct(
        public string $text,
        public ?string $problem = null,
        /** What the model wrote, each number blanked, when that can be shown struck out. */
        public ?string $struck = null,
    ) {
    }
}
