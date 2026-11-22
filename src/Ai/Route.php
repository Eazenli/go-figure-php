<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Where a free question goes before any page is written.
 */
enum Route: string
{
    /** The datasets of this machine can answer it. */
    case Answer = 'answer';
    /** A statistic a source may publish and that is not here yet: an agent goes and gets it. */
    case Fetch = 'fetch';
    /** Nothing the app answers: another subject, or one it leaves out on purpose. */
    case Decline = 'decline';
}
