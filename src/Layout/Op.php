<?php

declare(strict_types=1);

namespace App\Layout;

enum Op: string
{
    case Change = 'change';
    case ChangePct = 'change_pct';
    case Share = 'share';
    case Rank = 'rank';
}
