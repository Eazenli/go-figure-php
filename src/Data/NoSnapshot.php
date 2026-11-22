<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The clean table has not been built yet: no figure can be proven, so callers fail
 * loudly or say so, but never show a page without its numbers checked.
 */
final class NoSnapshot extends \RuntimeException
{
}
