<?php

declare(strict_types=1);

namespace App\Import;

/** A dimension of a Eurostat dataset, other than geo and time, with its codes and their labels. */
final readonly class Dimension
{
    /**
     * @param array<string, string> $codes code => label, in Eurostat's order
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $codes,
    ) {
    }
}
