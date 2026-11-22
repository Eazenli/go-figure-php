<?php

declare(strict_types=1);

namespace App\Proof;

use App\Layout\Figure;
use App\Layout\Series;

/**
 * A figure that cannot be proven: shown as a refusal card, never as a number.
 */
final readonly class Refusal
{
    public function __construct(
        public RefusalReason $reason,
        /** What the model asked for, to say it back in plain words. */
        public Figure|Series $requested,
    ) {
    }
}
