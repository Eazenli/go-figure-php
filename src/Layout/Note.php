<?php

declare(strict_types=1);

namespace App\Layout;

/**
 * A sentence the model writes around the figures. It cites figures and periods, never types them.
 */
final readonly class Note implements Component
{
    /**
     * @param list<Segment> $segments
     */
    public function __construct(
        public string $kind,
        public array $segments,
    ) {
    }
}
