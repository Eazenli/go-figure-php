<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * A line a source asks to show next to its figures, e.g. "Weather data by Open-Meteo.com" with a
 * link. Every page that shows a figure of the entry shows it, as does the proof.
 */
final readonly class Attribution
{
    public function __construct(
        /** Written by us in the catalog, without digits: it is shown on the page. */
        public string $text,
        /** An https link, or null. */
        public ?string $url = null,
    ) {
    }
}
