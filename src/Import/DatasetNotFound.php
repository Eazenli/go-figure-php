<?php

declare(strict_types=1);

namespace App\Import;

/** Eurostat has no dataset with this code (or the code is not one). */
final class DatasetNotFound extends \RuntimeException
{
    public function __construct(public readonly string $dataset)
    {
        parent::__construct(\sprintf('Eurostat has no dataset "%s".', $dataset));
    }
}
