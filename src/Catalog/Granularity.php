<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * How the periods of a catalog entry are written.
 */
enum Granularity: string
{
    /** "2024" */
    case Annual = 'annual';
    /** "2024-07" */
    case Monthly = 'monthly';
    /** "M01" to "M12": a climate normal, one value per month of the year. */
    case MonthOfYear = 'month_of_year';
}
