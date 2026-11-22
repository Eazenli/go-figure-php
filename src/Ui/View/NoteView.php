<?php

declare(strict_types=1);

namespace App\Ui\View;

use App\Proof\ComputedProof;
use App\Proof\MeasuredProof;
use App\Proof\Refusal;

final readonly class NoteView
{
    public const string KIND = 'note';

    /**
     * @param list<Words|MeasuredProof|ComputedProof|Refusal|PeriodView> $segments
     */
    public function __construct(
        public array $segments,
    ) {
    }
}
