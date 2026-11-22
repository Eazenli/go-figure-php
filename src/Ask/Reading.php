<?php

declare(strict_types=1);

namespace App\Ask;

use App\Layout\Page;

/**
 * What the words of a question named, read without a model: the page they make, or why none.
 */
final readonly class Reading
{
    /**
     * @param list<string> $matched what was recognised, in the page's language: places, indicators, the period
     * @param list<string> $know    when nothing can be shown, what the data has: indicators or places to name
     */
    public function __construct(
        public ?Page $page,
        public array $matched,
        public ?string $problem = null,
        public array $know = [],
        /** @var list<array{label: string, ask: string}> other questions one click away: another indicator for the same places */
        public array $indicators = [],
        /** @var list<array{label: string, ask: string, current: bool}> the same question for another month, when it names one */
        public array $months = [],
        /** @var list<array{label: string, ask: string, current: bool}> the same question with one place less (current) or one more */
        public array $places = [],
        /** @var list<string> the words of the question that named nothing, said with the answer */
        public array $unused = [],
        /** @var array<string, string> the indicators the page shows: dataset id => title */
        public array $datasets = [],
        /** The name the problem is about, when it has one. */
        public ?string $name = null,
    ) {
    }
}
