<?php

declare(strict_types=1);

namespace App\Layout;

/**
 * A value inside a note: the server writes it, with its proof link.
 */
final readonly class FigureRef implements Segment
{
    public function __construct(
        public string $kind,
        public Figure $figure,
    ) {
    }
}
