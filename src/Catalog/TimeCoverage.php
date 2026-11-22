<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * The periods a catalog entry covers, first and last included. Some places have gaps inside the
 * range: the table, not this range, says which cells exist.
 */
final readonly class TimeCoverage
{
    public function __construct(
        public Granularity $granularity,
        public string $from,
        public string $to,
        /** The reference period of a climate normal, e.g. "1991-2020"; null otherwise. */
        public ?string $normal = null,
    ) {
    }

    /**
     * Every period of the range, in order, written as the table writes them.
     *
     * @return list<string>
     */
    public function periods(): array
    {
        return match ($this->granularity) {
            Granularity::Annual => array_map(strval(...), range((int) $this->from, (int) $this->to)),
            Granularity::MonthOfYear => array_map(static fn (int $m): string => \sprintf('M%02d', $m), range(1, 12)),
            Granularity::Monthly => self::months($this->from, $this->to),
        };
    }

    /**
     * @return list<string>
     */
    private static function months(string $from, string $to): array
    {
        $months = [];
        for ($month = new \DateTimeImmutable($from.'-01'); ($period = $month->format('Y-m')) <= $to; $month = $month->modify('+1 month')) {
            $months[] = $period;
        }

        return $months;
    }
}
