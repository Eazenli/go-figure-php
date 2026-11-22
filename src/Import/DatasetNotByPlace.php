<?php

declare(strict_types=1);

namespace App\Import;

/**
 * A Eurostat dataset that is not given by place and period (exchange rates are by currency, for
 * instance): a page of Go Figure names a place and a period, so it cannot be drawn.
 */
final class DatasetNotByPlace extends \RuntimeException
{
    public function __construct(public readonly string $dataset)
    {
        parent::__construct(\sprintf('The Eurostat dataset "%s" is not given by place and period.', $dataset));
    }
}
