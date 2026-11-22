<?php

declare(strict_types=1);

namespace App\Import;

/** The World Bank does not answer, or not with what its API documents. */
final class WorldBankUnavailable extends \RuntimeException
{
}
