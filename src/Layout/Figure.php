<?php

declare(strict_types=1);

namespace App\Layout;

use Symfony\Component\Serializer\Attribute\DiscriminatorMap;

/**
 * A number on the page. The model names a cell (or an operation on cells); the server reads
 * and computes the value. The model never writes the value itself.
 */
#[DiscriminatorMap(typeProperty: 'type', mapping: [
    'measured' => Measured::class,
    'computed' => Computed::class,
])]
interface Figure
{
}
