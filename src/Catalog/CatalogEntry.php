<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Layout\Op;
use App\Proof\Source;

/**
 * One entry of the catalog: a source with every dimension frozen except place and
 * period. The model may only name an entry by its id; everything else here is written by us.
 */
final readonly class CatalogEntry
{
    /**
     * Placeholder in a source URL that varies by place, replaced with the value from $geoMap: the
     * source's own code (GRC), or its query for that place (latitude=…&longitude=…).
     */
    public const string PLACE = '{place}';

    /**
     * @param array<string, string> $dimensions frozen dimensions of the source, e.g. nace_r2 => I551
     * @param list<string>          $geo        place codes (Eurostat codes, e.g. EL for Greece)
     * @param array<string, string> $geoMap       Eurostat code => what replaces {place} in the URL,
     *                                            for sources that use other codes or coordinates
     * @param list<string>          $pitfalls
     * @param list<Op>              $operations
     * @param array<string, string> $names        Eurostat code => English name, for the places ICU
     *                                            does not know: cities (ES004C is Seville)
     * @param list<Attribution>     $attributions what the source asks to show next to its figures
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $provider,
        /** The dataset code at the source, e.g. tour_occ_ninat. */
        public string $sourceDataset,
        public string $url,
        public string $licence,
        public array $dimensions,
        public array $geo,
        public array $geoMap,
        public TimeCoverage $time,
        public string $unitCode,
        public string $unitLabel,
        public string $scope,
        public array $pitfalls,
        public array $operations,
        /** The question of the traveller it answers. */
        public Theme $theme,
        public array $names = [],
        /**
         * How Go Figure computes the value from what the source publishes, when it does: a monthly
         * normal averaged from daily values. Null when the source publishes the value itself.
         */
        public ?string $method = null,
        public array $attributions = [],
        /** @var array<string, string> the title and the scope in other languages than English, e.g. fr */
        public array $titles = [],
        /** @var array<string, string> */
        public array $scopes = [],
        /** @var array<string, string> */
        public array $methods = [],
    ) {
    }

    public function methodIn(string $locale): ?string
    {
        return $this->methods[$locale] ?? $this->method;
    }

    /** The title in the page's language when the catalog gives it, in English otherwise. */
    public function titleIn(string $locale): string
    {
        return $this->titles[$locale] ?? $this->title;
    }

    public function scopeIn(string $locale): string
    {
        return $this->scopes[$locale] ?? $this->scope;
    }

    /**
     * The same entry with one more place: a city whose weather was added on this machine.
     */
    public function withPlace(string $geo, string $name, string $place): self
    {
        if ($this->covers($geo)) {
            return $this;
        }

        return new self(...array_merge(get_object_vars($this), [
            'geo' => [...$this->geo, $geo],
            'geoMap' => $this->geoMap + [$geo => $place],
            'names' => $this->names + [$geo => $name],
        ]));
    }

    public function covers(string $geo): bool
    {
        return \in_array($geo, $this->geo, true);
    }

    /**
     * The source of one place: "Open on source" points at the page of that place when the URL
     * varies by place. The loader checks that every place of such an entry is in $geoMap.
     */
    public function source(string $geo): Source
    {
        return new Source($this->provider, str_replace(self::PLACE, $this->geoMap[$geo] ?? $geo, $this->url));
    }
}
