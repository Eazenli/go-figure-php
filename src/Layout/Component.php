<?php

declare(strict_types=1);

namespace App\Layout;

use Symfony\Component\Serializer\Attribute\DiscriminatorMap;

#[DiscriminatorMap(typeProperty: 'kind', mapping: [
    'kpi' => KpiTile::class,
    'bar' => BarChart::class,
    'line' => LineChart::class,
    'table' => Table::class,
    'note' => Note::class,
])]
interface Component
{
}
