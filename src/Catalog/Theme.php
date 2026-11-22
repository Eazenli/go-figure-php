<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * The question of the traveller an entry of the catalog answers. /compare groups its charts by
 * theme, in the order of the cases.
 */
enum Theme: string
{
    case Cost = 'cost';
    case Crowds = 'crowds';
    case Weather = 'weather';
    case Access = 'access';
    /** Not a question of the traveller: a dataset a visitor added about something else. */
    case Other = 'other';

    /** The question, in English: the templates translate it. */
    public function question(): string
    {
        return match ($this) {
            self::Cost => 'How much does it cost?',
            self::Crowds => 'Will it be crowded?',
            self::Weather => 'What will the weather be like?',
            self::Access => 'How do you get there?',
            self::Other => 'What else is there to know?',
        };
    }
}
