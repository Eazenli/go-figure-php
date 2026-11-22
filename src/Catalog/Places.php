<?php

declare(strict_types=1);

namespace App\Catalog;

use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The name of a place code of the catalog (Eurostat codes), in the page's language: "EL" is Greece
 * (Grèce), "UK" the United Kingdom, "ES004C" Seville (Séville). Country names come from ICU, city
 * names from the catalog (translated in the places domain); both are written by the server, and the
 * code stays on the proof.
 *
 * Eurostat's city codes start with their country: ES004C is a city of Spain. A figure published
 * for the whole country can stand next to the figure of a city, and the page says which is which.
 */
final class Places implements LocaleAwareInterface
{
    /**
     * A city of Eurostat's urban statistics: its country, a number, and C. ES004C is Seville; the
     * same number ending in K or F is the greater city or the commuting zone, not the city.
     */
    public const string CITY = '/^[A-Z]{2}\d{3}C$/D';
    /**
     * A place of a file someone deposited ("Lyon store", "Product A"): neither a country nor a
     * city. Its code is made from its name, so that the same name is the same place in every file.
     */
    public const string OWN = '/^Y_[0-9A-F]{10}$/D';
    /** What such a name may hold: letters, digits and a few signs, short. It is the depositor's own label, not a model's text. */
    public const string OWN_NAME = '/^[\p{L}\p{N}][\p{L}\p{N} .&\'’\/-]{0,39}$/Du';
    /** Countries whose English name takes "the": "a city in the Netherlands". */
    private const array THE = ['NL', 'UK'];
    /** Eurostat codes that differ from ISO 3166, and aggregates. */
    private const array NAMES = ['EU27_2020' => 'European Union (without the United Kingdom)', 'EU28' => 'European Union (with the United Kingdom)', 'EA20' => 'Euro area'];
    private const array ISO = ['EL' => 'GR', 'UK' => 'GB'];

    /** The language of the page: country names are ICU's in that language ("Spain", "Espagne"). */
    private string $locale = 'en';

    public function __construct(
        private readonly Catalog $catalog,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function name(string $code): string
    {
        $city = $this->catalog->placeName($code);

        return match (true) {
            isset(self::NAMES[$code]) => $this->translator->trans(self::NAMES[$code]),
            // The catalog names a city in English; the places domain gives its name in the page's language.
            null !== $city => $this->translator->trans($city, domain: 'places'),
            default => self::countryName($code, $this->locale),
        };
    }

    /** How much a figure of this place covers, as the proof says it: "the whole country", "a city in Spain". */
    public function scale(string $code): string
    {
        return match (true) {
            self::isCountry($code) => $this->translator->trans('the whole country'),
            self::isCity($code) => $this->translator->trans(\in_array(substr($code, 0, 2), self::THE, true) ? 'a city in the %country%' : 'a city in %country%', ['%country%' => self::countryName(substr($code, 0, 2), $this->locale)]),
            isset(self::NAMES[$code]) => $this->translator->trans('a group of countries'),
            default => '',
        };
    }

    /** The place in a list of choices, by its name: "Spain", "Seville, Spain". Its code is on each proof. */
    public function label(string $code): string
    {
        $name = $this->name($code);
        if ($name === $code) {
            return $code;
        }

        return self::isCity($code) ? \sprintf('%s, %s', $name, self::countryName(substr($code, 0, 2), $this->locale)) : $name;
    }

    /**
     * Places for a list of choices, grouped and sorted by name: countries, cities, groups of
     * countries, and any other code. A single kind needs no group.
     *
     * @param list<string> $codes
     *
     * @return list<string>|array<string, list<string>>
     */
    public function choices(array $codes): array
    {
        $groups = ['Countries' => [], 'Cities' => [], 'Groups of countries' => [], 'Other places' => []];
        foreach (array_unique($codes) as $code) {
            $groups[match (true) {
                self::isCountry($code) => 'Countries',
                self::isCity($code) => 'Cities',
                isset(self::NAMES[$code]) => 'Groups of countries',
                default => 'Other places',
            }][] = $code;
        }
        // In the alphabetical order of the page's language: "Suède" before "Suisse".
        $collator = new \Collator($this->locale);
        foreach ($groups as $kind => $places) {
            usort($places, fn (string $a, string $b): int => (int) $collator->compare($this->name($a), $this->name($b)));
            $groups[$kind] = $places;
        }
        $groups = array_filter($groups);

        return 1 === \count($groups) ? reset($groups) : $groups;
    }

    public static function isOwn(string $code): bool
    {
        return 1 === preg_match(self::OWN, $code);
    }

    /** The code of a place of a deposited file, from its name. */
    public static function ownCode(string $name): string
    {
        return 'Y_'.strtoupper(substr(sha1(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)))), 0, 10));
    }

    public static function isCountry(string $code): bool
    {
        return 1 === preg_match('/^[A-Z]{2}$/D', $code);
    }

    public static function isCity(string $code): bool
    {
        return 1 === preg_match(self::CITY, $code);
    }

    /** The country of a city ("ES" for ES004C), a country itself, or null for an aggregate. */
    public static function country(string $code): ?string
    {
        return match (true) {
            self::isCountry($code) => $code,
            self::isCity($code) => substr($code, 0, 2),
            default => null,
        };
    }

    /**
     * The name ICU gives a country code in a language, or the name of an aggregate (in English, as
     * Eurostat writes it); the code itself otherwise.
     */
    public static function countryName(string $code, string $locale = 'en'): string
    {
        if (isset(self::NAMES[$code])) {
            return self::NAMES[$code];
        }
        if (!self::isCountry($code)) {
            return $code;
        }
        $name = \Locale::getDisplayRegion('und_'.(self::ISO[$code] ?? $code), $locale);

        return '' !== $name && $name !== (self::ISO[$code] ?? $code) ? $name : $code;
    }
}
