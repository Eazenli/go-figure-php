<?php

declare(strict_types=1);

namespace App\Layout;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * An operation on cells. One level only: operands are cells, never other operations.
 */
final readonly class Computed implements Figure
{
    /**
     * @param list<Measured> $operands
     */
    public function __construct(
        public string $type,
        #[Schema(description: 'change: one place of one dataset at two periods, the later minus the earlier. change_pct: the same, in percent of the earlier. share: a part then its whole (two datasets of one source and unit, same place and period), in percent. rank: the place of the first cell among all the cells given (one dataset, one period, different places), highest first.')]
        public Op $op,
        #[Schema(description: 'The cells the server computes from, in the order the operation reads them: two, or up to eight for a rank. Never a result.')]
        public array $operands,
    ) {
    }
}
