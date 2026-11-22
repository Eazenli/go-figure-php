<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * The stream ended without a page: the model refused, or wrote no text at all.
 */
final class NoPageInStream extends \RuntimeException
{
}
