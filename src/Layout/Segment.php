<?php

declare(strict_types=1);

namespace App\Layout;

use Symfony\Component\Serializer\Attribute\DiscriminatorMap;

#[DiscriminatorMap(typeProperty: 'kind', mapping: [
    'text' => Text::class,
    'figure' => FigureRef::class,
    'period' => PeriodRef::class,
])]
interface Segment
{
}
