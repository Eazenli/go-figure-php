<?php

declare(strict_types=1);

namespace App\Ask;

use App\Catalog\Catalog;
use App\Catalog\Places;

/**
 * The names a next question is written with: the title of a dataset and the name of a place, in
 * English, as the reader of questions reads them back.
 */
final readonly class NextQuestions
{
    public function __construct(
        private Catalog $catalog,
        private Places $places,
    ) {
    }

    public function datasetTitle(string $dataset): string
    {
        $entry = $this->catalog->get($dataset);

        return null === $entry ? $dataset : $entry->title;
    }

    public function placeName(string $geo): string
    {
        return $this->places->name($geo);
    }
}
