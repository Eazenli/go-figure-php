<?php

declare(strict_types=1);

namespace App\Ui\View;

final readonly class PeriodView
{
    public function __construct(
        public string $from,
        public string $to,
        public ?string $problem = null,
    ) {
    }
}
