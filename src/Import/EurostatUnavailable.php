<?php

declare(strict_types=1);

namespace App\Import;

/** Eurostat did not answer, or not in time, or too much: the import is not done, nothing is kept. */
final class EurostatUnavailable extends \RuntimeException
{
}
