<?php

declare(strict_types=1);

namespace App\Ask;

use App\Catalog\Catalog;
use App\Catalog\CatalogEntry;
use App\Catalog\Granularity;
use App\Catalog\Places;
use App\Catalog\Theme;
use App\Composer\PageComposer;
use App\Import\FileImporter;
use App\Layout\Page;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads a question without a model: the places, the indicators and the period its words name, in
 * the catalog of this machine. It matches words, it does not understand: a question it cannot
 * place gets no page, and is told what the data has. The page it builds is the layout a model
 * would send, checked and proven the same way.
 */
final class QuestionReader
{
    /**
     * The words that name an indicator of the shipped catalog, in English and in French. A dataset
     * that is not here (one added on this machine) is named by the words of its title.
     */
    private const array WORDS = [
        'temp_max_city' => ['chaud', 'chaude', 'chaudes', 'fait combien', 'warm', 'warmer', 'warmest', 'hot', 'hotter', 'hottest', 'cold', 'colder', 'coldest', 'cool', 'cooler', 'temperature', 'temperatures', 'heat', 'weather', 'chaud', 'chaude', 'froid', 'froide', 'température', 'températures', 'météo'],
        'rain_city' => ['pluvieuse', 'pluvieuses', 'pleuvoir', 'rain', 'rains', 'pleut', 'pleut-il', 'pleuvoir', 'rainy', 'rainier', 'rainiest', 'rainfall', 'wet', 'wetter', 'wettest', 'dry', 'drier', 'driest', 'precipitation', 'weather', 'pluie', 'pluies', 'pluvieux', 'sec', 'météo'],
        'prices_restaurants_hotels' => ['restaurant', 'restaurants', 'eating out', 'manger', 'eat', 'eating', 'hotel price', 'hotel prices', 'price of hotels', 'price of a hotel', 'prix hotel', 'prix des hotels', 'prix des hôtels', 'money', 'chers', 'chères', 'vacances', 'holiday', 'holidays', 'cheap', 'cheaper', 'cheapest', 'expensive', 'price', 'prices', 'cost', 'costs', 'affordable', 'cher', 'chère', 'prix', 'coût'],
        'prices_food' => ['food', 'groceries', 'grocery', 'supermarket', 'shops', 'price of food', 'alimentation', 'courses', 'nourriture'],
        'nights_hotels' => ['hotel', 'hotels', 'hôtel', 'hôtels'],
        'nights_total' => ['nights', 'tourism', 'tourist', 'tourists', 'touristy', 'touristique', 'touristiques', 'busy', 'busier', 'busiest', 'crowded', 'crowds', 'visitors', 'popular', 'nuitées', 'tourisme', 'touristes', 'fréquenté', 'fréquentée'],
        'nights_monthly' => ['season', 'seasons', 'peak', 'by month', 'month by month', 'saison', 'par mois'],
        'tourism_intensity' => ['intensity', 'per inhabitant', 'for its size', 'relative to its population', 'relative to their population', 'par rapport à sa population', 'par rapport a sa population', 'overtourism', 'crowded', 'crowds', 'surtourisme', 'par habitant'],
        'air_passengers' => ['flight', 'flights', 'fly', 'flying', 'air passengers', 'air travel', 'airport', 'airports', 'plane', 'planes', 'avion', 'avions', 'vol', 'vols', 'aéroport', 'aéroports'],
        'rail_passengers' => ['train', 'trains', 'rail', 'railway', 'railways', 'ferroviaire'],
        'rail_passenger_km' => ['passenger-kilometres', 'passenger kilometres', 'passenger-kilometers', 'distance by rail'],
        'train_share' => ['share of trains', 'rather than by road', 'modal share', 'part du train', 'souvent le train', 'train le plus souvent', 'train most often', 'most often by train', 'often by train'],
        'gdp_per_inhabitant' => ['gdp', 'gdp per capita', 'gross domestic product', 'economy', 'rich', 'richer', 'richest', 'wealthy', 'wealthier', 'wealthiest', 'riche', 'riches', 'pib par habitant', 'pib', 'produit intérieur brut'],
        'inflation' => ['inflation', 'rising prices', 'price rises', 'price increases', 'hausse des prix'],
        'house_prices' => ['house prices', 'house price', 'housing prices', 'housing', 'property prices', 'home prices', 'real estate', 'immobilier', "prix de l'immobilier", 'prix immobiliers', 'prix des logements', 'prix du logement', 'prix des maisons'],
        'renewable_share' => ['renewable', 'renewables', 'renewable energy', 'green energy', 'clean energy', 'renouvelable', 'renouvelables', 'énergie renouvelable', 'énergies renouvelables', 'énergie verte'],
        'municipal_waste' => ['waste', 'rubbish', 'garbage', 'trash', 'déchets', 'ordures', 'poubelles'],
        'forest_area' => ['forest', 'forests', 'woodland', 'woods', 'forêt', 'forêts'],
        // Not "car" alone: in French it is "because".
        'cars_per_inhabitant' => ['cars', 'passenger cars', 'car ownership', 'cars per inhabitant', 'voitures par habitant', 'combien de voitures', 'nombre de voitures', 'taux de motorisation'],
        'population' => ['population', 'inhabitants', 'populated', 'populous', 'people live', 'habitants', 'peuplé', 'peuplés'],
    ];
    /**
     * Pairs a vague word cannot tell apart: "tourism" is all accommodation or hotels alone,
     * "cheap" is eating out or the shops. Unless the question says which, both are shown.
     */
    private const array TWINS = ['nights_total' => 'nights_hotels', 'prices_restaurants_hotels' => 'prices_food'];
    /** The words that say which of a pair is meant. */
    private const array SURE = [
        'nights_total' => ['all accommodation', 'tourist accommodation', 'tourist nights', 'nuitées touristiques', 'nuitees touristiques', 'campsites', 'camping', 'hébergements'],
        'nights_hotels' => ['hotel', 'hotels', 'hôtel', 'hôtels'],
        'prices_restaurants_hotels' => ['restaurant', 'restaurants', 'eating out'],
        'prices_food' => ['food', 'groceries', 'grocery', 'supermarket', 'shops', 'alimentation', 'courses', 'nourriture'],
    ];
    /** Questions to click when no model answers: kept only if the data of this machine answers them. */
    private const array EXAMPLES = [
        'Is Lisbon or Seville warmer in September?',
        'Which is cheaper for restaurants: Portugal, Spain or Croatia?',
        'How has tourism in Portugal changed since 2015?',
        'House prices in Portugal since 2015',
        'Which country has the most renewable energy?',
        'Rain in Nice, Malaga and Split',
        'Flights to Greece and Italy',
        'Tourism in France and Spain',
    ];
    private const array EXAMPLES_FR = [
        'Fait-il plus chaud à Lisbonne ou à Séville en septembre ?',
        'Quel est le moins cher pour les restaurants : Portugal, Espagne ou Croatie ?',
        'Tourisme au Portugal depuis 2015',
        "Prix de l'immobilier au Portugal depuis 2015",
        'Quel pays a le plus d’énergies renouvelables ?',
        'Pluie à Nice, Malaga et Split',
        'Tourisme en France et en Espagne',
    ];
    /** Words that say "over time": the answer is a line, from the first year the question gives. */
    private const array TREND = ['since', 'trend', 'evolution', 'over time', 'over the years', 'changed', 'depuis', 'évolution', 'tendance'];
    /** Names that are also ordinary words: taken as a place only with their capital. */
    private const array ALSO_WORDS = ['nice', 'split', 'bath', 'reading', 'cork', 'tours', 'nancy', 'vienne'];
    /** Words of a title that name nothing. */
    private const array EMPTY_WORDS = ['with', 'from', 'that', 'this', 'total', 'average', 'rate', 'level', 'number', 'value', 'index', 'share', 'city', 'country', 'year', 'month', 'dans', 'pour', 'avec', 'moyenne', 'ville', 'pays'];
    /** Words that carry no subject: what is left of a question besides them was not understood. */
    private const array SMALL_WORDS = [
        'a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been', 'it', 'its', 'in', 'on', 'at', 'of', 'for', 'to', 'from', 'by', 'with', 'and', 'or', 'vs', 'versus', 'than', 'then', 'as', 'do', 'does', 'did', 'has', 'have', 'had', 'get', 'gets', 'got', 'take', 'takes',
        'how', 'what', 'which', 'where', 'when', 'who', 'why', 'much', 'many', 'more', 'most', 'like', 'there', 'that', 'this', 'these', 'those', 'not', 'no', 'so', 'very', 'too', 'between', 'compared', 'compare', 'about', 'per', 'each', 'any', 'some', 'all',
        'country', 'countries', 'city', 'cities', 'place', 'places', 'europe', 'european', 'year', 'years', 'month', 'months', 'people', 'number', 'level', 'average', 'high', 'higher', 'highest', 'big', 'bigger', 'biggest', 'best', 'better', 'good', 'i', 'we', 'you', 'they', 'me', 'my', 'our', 'can', 'could', 'would', 'should', 'will', 'go', 'going', 'back', 'now', 'today', 'still', 'also', 'only', 'just', 'out', 'up', 'down', 'over', 'time', 'since', 'during', 'before', 'after', 'ever',
        'capita', 'inhabitant', 'habitant', 'produce', 'produces', 'produit', 'rise', 'rose', 'risen', 'grow', 'grew', 'grown', 'own', 'owns',
        'interessant', 'interessante', 'interessants', 'deal', 'looking', 'receive', 'receives', 'during', 'likely', 'accueille', 'accueillent', 'recoit', 'recoivent', 'worth',
        'est-il', 'est-elle', 'lequel', 'laquelle', 'lesquels', 'lesquelles', 'trop', 'pas', 'ne', 'tu', 'me', 'nous', 'vous', 'coute', 'coutent', 'encore', 'still', 'cherche', 'voudrais', 'veux', 'conseilles', 'conseillez', 'recommend', 'chaque', 'every', 'davantage', 'souvent', 'often', 'prennent', 'prend', 'take', 'takes',
        'whats', 'what s', 'quoi', 'cest', 'partir', 'aller', 'going', 'visit', 'visiter', 'peux', 'puis', 'je', 'on', 'faire', 'est-ce', 'gens',
        'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'd', 'l', 'au', 'aux', 'en', 'et', 'ou', 'que', 'qu', 'qui', 'quel', 'quelle', 'quels', 'quelles', 'est', 'sont', 'il', 'elle', 'fait', 'plus', 'moins', 'pour', 'par', 'sur', 'dans', 'avec', 'sans', 'ce', 'cette', 'ces', 'y', 't', 'a', 'combien', 'comment', 'ou', 'entre', 'depuis', 'pays', 'ville', 'villes', 'annee', 'mois',
    ];
    /** Words that say little alone: with a word of another subject ("cheap flights"), they name nothing. */
    private const array VAGUE = ['money', 'chers', 'chères', 'vacances', 'holiday', 'holidays', 'cheap', 'cheaper', 'cheapest', 'expensive', 'price', 'prices', 'cost', 'costs', 'affordable', 'cher', 'chère', 'prix', 'coût', 'busy', 'busier', 'busiest', 'popular', 'crowded', 'crowds', 'habitants', 'inhabitants'];
    /** Names of a country that the sources do not use. */
    private const array OTHER_NAMES = ['czech republic' => 'CZ', 'republique tcheque' => 'CZ', 'holland' => 'NL', 'hollande' => 'NL', 'turkey' => 'TR', 'turquie' => 'TR'];
    /** Words that ask for the bottom of a ranking rather than its top. */
    private const array LOWEST = ['least', 'lowest', 'low', 'lower', 'less', 'fewer', 'fewest', 'smallest', 'cheap', 'cheaper', 'cheapest', 'affordable', 'cold', 'colder', 'coldest', 'cool', 'cooler', 'coolest', 'dry', 'drier', 'driest', 'moins', 'froid', 'froide', 'sec', 'pas cher', 'pas chers', 'pas chère', 'pas chères', 'pas trop cher', 'pas trop chers', 'pas trop chère', 'pas trop chères', 'abordable', 'abordables', 'bon marché', 'least money', 'not expensive', 'not too expensive', 'inexpensive'];
    /** The words that say a price, and those that say a hotel: together, the price of hotels, not their nights. */
    private const array PRICE = ['cheap', 'cheaper', 'cheapest', 'expensive', 'price', 'prices', 'cost', 'costs', 'money', 'cher', 'chers', 'chère', 'chères', 'prix', 'coût'];
    /** A season is its months. */
    private const array SEASONS = [
        'summer' => ['06', '07', '08'], 'été' => ['06', '07', '08'], 'ete' => ['06', '07', '08'],
        'winter' => ['12', '01', '02'], 'hiver' => ['12', '01', '02'],
        'spring' => ['03', '04', '05'], 'printemps' => ['03', '04', '05'],
        'autumn' => ['09', '10', '11'], 'automne' => ['09', '10', '11'],
    ];
    /** How many places a question that names none is answered with. */
    private const int RANKED = 10;
    /** Words of a comparison: one of its terms missing, the page would answer another question. */
    private const array COMPARE = ['or', 'vs', 'versus', 'than', 'and', 'compared', 'between', 'ou', 'et', 'que', 'entre'];
    /** "Per inhabitant" belongs to the subject it follows: alone, it is not the tourism intensity. */
    private const array PER = ['per inhabitant', 'par habitant'];
    /** Words that ask how expensive something is, not how its price moved. */
    private const array LEVEL = ['cheap', 'cheaper', 'cheapest', 'affordable', 'expensive', 'cher', 'chers', 'chère', 'chères', 'abordable', 'abordables'];
    /**
     * Words that make another question of it, one the data cannot answer: the temperature of the
     * water is not that of the air, the rain of tomorrow is not the average of a month.
     */
    private const array ANOTHER_QUESTION = ['water temperature', 'temperature of the water', 'sea temperature', 'temperature of the sea', "température de l'eau", 'température de la mer', 'demain', 'tomorrow', 'ce soir', 'tonight', 'ce week-end', 'this weekend', 'semaine prochaine', 'next week', 'prévision', 'prévisions', 'forecast', 'forecasts', 'en ce moment', 'right now'];
    private const int MAX_PLACES = 6;
    private const int MAX_INDICATORS = 3;

    /** @var array<string, array<string, string>> the places by their plain name, by language: built once */
    private array $names = [];

    public function __construct(
        private readonly Catalog $catalog,
        private readonly Places $places,
        private readonly PageComposer $composer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param bool $refine whether to work out the next questions (another month, figure or place):
     *                     not needed to know that a question gets a page
     */
    public function read(string $question, bool $refine = true): Reading
    {
        $text = ' '.self::plain($question).' ';
        $locale = $this->translator->getLocale();

        $places = $this->placesIn($question, $text);
        [$months, $from, $to] = $this->periodIn($text, $locale);
        // A season names its months: each is shown. Any other second month is said as left out.
        $season = [];
        foreach (self::SEASONS as $word => $its) {
            if ([] === $months && str_contains($text, ' '.self::plain($word).' ')) {
                $season = $months = $its;
            }
        }
        // Several months named ("in July or in August"): each is shown, like those of a season.
        if ([] === $season && \count($months) > 1) {
            $season = $months;
        }
        $month = $months[0] ?? null;
        [$entries, $twins, $vagueOnly, $dropped] = $this->indicatorsIn($text, null !== $month, $places);
        foreach (self::ANOTHER_QUESTION as $word) {
            if ([] !== $entries && str_contains($text, ' '.self::plain($word).' ')) {
                return new Reading(null, [], 'The data does not know "%name%": a page without it would answer another question.', [], name: $word);
            }
        }
        // A country asked a figure that only its cities have (the weather): its cities answer.
        $asked = $places;
        $cities = [];
        foreach ($places as $at => $geo) {
            if (Places::isCountry($geo) && [] !== $entries && [] === array_filter($entries, static fn (CatalogEntry $entry): bool => $entry->covers($geo))) {
                $its = [];
                foreach ($entries as $entry) {
                    $its = [...$its, ...array_filter($entry->geo, static fn (string $city): bool => Places::isCity($city) && Places::country($city) === $geo)];
                }
                if ([] !== $its) {
                    $cities[$geo] = \array_slice(array_values(array_unique($its)), 0, self::MAX_PLACES);
                }
            }
        }
        if ([] !== $cities) {
            // The places named as they are stay; each country gets its share of what is left.
            $kept = array_values(array_filter($places, static fn (string $geo): bool => !isset($cities[$geo])));
            $each = max(1, intdiv(max(0, self::MAX_PLACES - \count($kept)), \count($cities)));
            $swapped = [];
            foreach ($places as $geo) {
                $swapped = [...$swapped, ...(isset($cities[$geo]) ? \array_slice($cities[$geo], 0, $each) : [$geo])];
            }
            $places = \array_slice(array_values(array_unique($swapped)), 0, self::MAX_PLACES);
        }

        // The periods the data of these indicators has: a year it lacks is said, not swapped in silence.
        $has = static fn (?string $year): bool => null !== $year && [] !== array_filter($entries, static fn (CatalogEntry $entry): bool => match ($entry->time->granularity) {
            Granularity::Annual => \in_array($year, $entry->time->periods(), true),
            Granularity::Monthly => null !== $month && \in_array($year.'-'.$month, $entry->time->periods(), true),
            Granularity::MonthOfYear => false,
        });
        $lost = [];
        foreach ([&$from, &$to] as &$year) {
            if (null !== $year && !$has($year)) {
                $lost[] = $year;
                $year = null;
            }
        }
        unset($year);

        $matched = [
            ...array_map($this->places->name(...), $places),
            ...array_map(static fn (CatalogEntry $entry): string => $entry->titleIn($locale), $entries),
            ...array_map(static fn (string $code): string => PageComposer::monthName($code, $locale), [] === $season ? (null === $month ? [] : [$month]) : $season),
            ...(null === $from ? [] : [$from]),
            ...(null === $to || $to === $from ? [] : [$to]),
        ];

        $stranger = $this->strangerIn($question, $locale);
        $compares = [] !== array_filter(self::COMPARE, static fn (string $word): bool => str_contains($text, ' '.$word.' '));
        if (null !== $stranger && $compares) {
            return new Reading(null, $matched, 'The data does not know "%name%": a page without it would answer another question.', [], name: $stranger);
        }
        if ([] === $entries) {
            // What the data has, for the places named when there are some.
            $know = [];
            foreach ($this->catalog->all() as $entry) {
                if ([] === $places || [] !== array_filter($places, static fn (string $geo): bool => $entry->covers($geo) || (null !== ($country = Places::country($geo)) && $entry->covers($country)))) {
                    $know[$entry->titleIn($locale)] = true;
                }
            }

            return new Reading(null, $matched, 'The words of this question name no indicator of the data.', array_map(strval(...), array_keys($know)));
        }
        // The only period asked for is one the data does not have: no page about another one.
        if ([] !== $lost && null === $from && null === $to && null === $month) {
            return new Reading(null, $matched, 'The data has no figure for %name%.', array_map(fn (CatalogEntry $entry): string => \sprintf('%s: %s %s %s', $entry->titleIn($locale), $entry->time->from, $this->translator->trans('to'), $entry->time->to), $entries), name: implode(', ', $lost));
        }
        // A comparison of places needs two the data has: with one, or none, the page would answer
        // another question ("Tokyo or Lisbon", "UK or France", "tokyo vs osaka").
        $strict = [] !== array_filter(['vs', 'versus', 'than', 'compared', 'between', 'entre'], static fn (string $word): bool => str_contains($text, ' '.$word.' '));
        // "or" compares places only when a word the data does not know stands right next to it
        // ("Tokyo or Lisbon", "UK or France"), not in "by plane or by train". The French "où" is not "ou".
        $unknown = $this->unused($text, [...$places, ...array_map(strval(...), array_keys($cities))], $entries, $locale, min: 2);
        $loose = false;
        if (1 === preg_match_all('/(\S+) (?:or|ou) (\S+)/u', ' '.self::plain((string) preg_replace('/\boù\b/iu', 'where', $question)).' ', $sides, \PREG_SET_ORDER) || [] !== $sides) {
            foreach ($sides as $side) {
                $loose = $loose || \in_array($side[1], $unknown, true) || \in_array($side[2], $unknown, true);
            }
        }
        if (($strict || $loose) && \count($asked) < 2 && ([] !== $asked || [] !== $unknown || null !== $stranger)) {
            return new Reading(null, $matched, [] === $asked ? 'This compares places the data does not have.' : 'This compares places, and the data has only %name% of them.', [], name: implode(', ', array_map($this->places->name(...), $asked)));
        }
        // A name was given that the data does not know, and nothing else: not a ranking of all places.
        if ([] === $places && null !== $stranger) {
            return new Reading(null, $matched, 'The data does not know "%name%": a page without it would answer another question.', [], name: $stranger);
        }
        // No place named ("which country is cheapest?"): the top of all the places, or the bottom.
        $ranked = null;
        if ([] === $places) {
            $ranked = [] !== array_filter(self::LOWEST, static fn (string $word): bool => str_contains($text, ' '.self::plain($word).' ')) ? 'lowest' : 'highest';
            $places = $this->composer->ranked($entries[0], $month, $to, 'lowest' === $ranked, self::RANKED);
            if ([] === $places && Granularity::MonthOfYear === $entries[0]->time->granularity && null === $month) {
                return new Reading(null, $matched, 'This figure is by month of the year: name a month, or a place.', array_map(static fn (CatalogEntry $entry): string => $entry->titleIn($locale), $entries));
            }
        }
        if ([] === $places) {
            $know = [];
            foreach ($entries as $entry) {
                foreach (\array_slice($entry->geo, 0, 12) as $geo) {
                    $know[$this->places->name($geo)] = true;
                }
            }

            return new Reading(null, $matched, 'The words of this question name no place of the data.', \array_slice(array_map(strval(...), array_keys($know)), 0, 12));
        }

        // What named nothing: words, a name, a second month, a year the data lacks.
        $unused = array_values(array_unique([
            ...($words = $this->unused($text, [...$places, ...array_map(strval(...), array_keys($cities))], $entries, $locale)),
            ...$dropped,
            // The name once: as a word left over it is already there, without its capital.
            ...(null === $stranger || \in_array(self::plain($stranger), $words, true) ? [] : [$stranger]),
            ...array_map(static fn (string $extra): string => PageComposer::monthName($extra, $locale), [] === $season ? \array_slice($months, 1) : []),
            ...$lost,
        ]));
        // An indicator found by a vague word only ("cheapest"), and a word left over ("beer"): the
        // page would be about something else than the question. None is shown.
        if ($vagueOnly && [] !== array_diff($unused, $lost)) {
            return new Reading(null, $matched, 'Not understood: %name%. Without it, the rest of the question is too vague to answer.', array_values(array_map(static fn (CatalogEntry $entry): string => $entry->titleIn($locale), $this->catalog->all())), name: implode(', ', array_diff($unused, $lost)));
        }

        // Two readings of a vague word: said first, then both shown, each with what it counts.
        $byCity = [] === $cities ? '' : $this->translator->trans('%countries%: the data has this figure by city, not by country. Some of its cities are shown instead.', ['%countries%' => implode(', ', array_map($this->places->name(...), array_map(strval(...), array_keys($cities))))]);
        $lead = [] === $twins ? null : $this->translator->trans('This question can be read in more than one way: %first%, or %second%. Both are shown, and each says what it counts.', ['%first%' => $twins[0][0]->titleIn($locale), '%second%' => $twins[0][1]->titleIn($locale)]);
        if ('' !== $byCity) {
            $lead = trim($byCity.' '.($lead ?? ''));
        }
        if (null !== $ranked) {
            // Said first: the places were chosen by the server, by their value. Each figure has its
            // own ranking, on the period it is shown for: the ten cheapest for restaurants are not
            // the ten cheapest for food, nor the ten of July those of August.
            $lead = trim($this->translator->trans('highest' === $ranked ? 'No place was named: for each figure, these are the highest of all the places the data has.' : 'No place was named: for each figure, these are the lowest of all the places the data has.').' '.($lead ?? ''));
            $page = null;
            foreach ([] === $season ? [$month] : $season as $at) {
                foreach ($entries as $entry) {
                    // A figure by year is ranked once, not once per month of a season.
                    if (null !== $page && Granularity::Annual === $entry->time->granularity && $at !== ([] === $season ? $month : $season[0])) {
                        continue;
                    }
                    $top = $this->composer->ranked($entry, $at, $to, 'lowest' === $ranked, self::RANKED);
                    $block = [] === $top ? null : $this->composer->answer([$entry], $top, $at, $from, $to, null === $page ? $lead : null, ranked: true);
                    if (null !== $block) {
                        $page = null === $page ? $block : new Page($page->title, [...$page->components, ...$block->components]);
                    }
                }
            }
        } else {
            $page = $this->composer->answer($entries, $places, $month, $from, $to, $lead);
            // The other months of a season, for the figures that are by month.
            $byMonth = array_values(array_filter($entries, static fn (CatalogEntry $entry): bool => Granularity::Annual !== $entry->time->granularity));
            if (null !== $page && [] !== $byMonth) {
                foreach (\array_slice($season, 1) as $next) {
                    $more = $this->composer->answer($byMonth, $places, $next, $from, $to);
                    if (null !== $more) {
                        $page = new Page($page->title, [...$page->components, ...$more->components]);
                    }
                }
            }
        }
        if (null === $page) {
            return new Reading(null, $matched, 'The data has this indicator, but not for these places or this period.', array_map(static fn (CatalogEntry $entry): string => $entry->titleIn($locale), $entries));
        }
        $datasets = [];
        foreach ($entries as $entry) {
            $datasets[$entry->id] = $entry->titleIn($locale);
        }
        if (!$refine) {
            return new Reading($page, $matched, unused: $unused, datasets: $datasets);
        }

        // One click away: another place, another month, another indicator. Each next question names
        // every indicator shown by its title, so two readings stay two.
        $titles = implode(', ', array_map(static fn (CatalogEntry $entry): string => $entry->titleIn($locale), $entries));
        $list = implode(', ', array_map($this->places->name(...), $places));
        $when = null === $month ? '' : ' '.$this->translator->trans('in %month%', ['%month%' => PageComposer::monthName($month, $locale)]);
        // The years of the question stay in the next one: "since 2015", "2019 to 2024", "in 2024".
        $years = match (true) {
            null !== $from && null !== $to => \sprintf(' %s %s', $from, $to),
            null !== $from => ' since '.$from,
            null !== $to => ' '.$to,
            default => '',
        };
        $shown = array_map(static fn (CatalogEntry $entry): string => $entry->id, $entries);
        $indicators = [];
        foreach ($this->catalog->all() as $entry) {
            if (!\in_array($entry->id, $shown, true) && null !== $this->composer->answer([$entry], $places, $month, $from, $to)) {
                $indicators[] = ['label' => $entry->titleIn($locale), 'ask' => \sprintf('%s: %s%s', $entry->titleIn($locale), $list, $when.$years), 'entry' => $entry];
            }
        }
        $monthChips = [];
        if (null !== $month) {
            foreach (range(1, 12) as $number) {
                $code = \sprintf('%02d', $number);
                $monthChips[] = ['label' => PageComposer::monthName($code, $locale, short: true), 'ask' => \sprintf('%s: %s %s', $titles, $list, $this->translator->trans('in %month%', ['%month%' => PageComposer::monthName($code, $locale)]).$years), 'current' => $code === $month];
            }
        }
        // The places: each one named can go (while another stays), a few more can come.
        $ask = fn (array $codes): string => \sprintf('%s: %s%s', $titles, implode(', ', array_map($this->places->name(...), $codes)), $when.$years);
        $chips = [];
        if (\count($places) > 1) {
            foreach ($places as $geo) {
                $chips[] = ['label' => $this->places->name($geo), 'ask' => $ask(array_values(array_filter($places, static fn (string $other): bool => $other !== $geo))), 'current' => true];
            }
        }
        if (\count($places) < self::MAX_PLACES) {
            // Of the kind already named: cities with cities, countries with countries.
            $city = Places::isCity($places[0]);
            $more = array_values(array_filter($entries[0]->geo, static fn (string $geo): bool => !\in_array($geo, $places, true) && Places::isCity($geo) === $city && ($city || Places::isCountry($geo))));
            usort($more, fn (string $a, string $b): int => strcmp($this->places->name($a), $this->places->name($b)));
            foreach (\array_slice($more, 0, 10) as $geo) {
                $chips[] = ['label' => $this->places->name($geo), 'ask' => $ask([...$places, $geo]), 'current' => false];
            }
        }
        // First the indicators that have these very places, then those that only have their country.
        $direct = static fn (array $offer): int => (int) ([] === array_filter($places, static fn (string $geo): bool => !$offer['entry']->covers($geo)));
        usort($indicators, static fn (array $a, array $b): int => $direct($b) <=> $direct($a));
        $indicators = array_map(static fn (array $offer): array => ['label' => $offer['label'], 'ask' => $offer['ask']], $indicators);

        return new Reading($page, $matched, indicators: \array_slice($indicators, 0, 8), months: $monthChips, places: $chips, unused: $unused, datasets: $datasets);
    }

    /**
     * Questions that work on the data of this machine, to click: each one is read first, and
     * offered only if it gets a page.
     *
     * @return list<string>
     */
    public function examples(): array
    {
        $questions = 'fr' === $this->translator->getLocale() ? self::EXAMPLES_FR : self::EXAMPLES;
        $kept = [];
        foreach ($questions as $question) {
            if (null !== $this->read($question, refine: false)->page) {
                $kept[] = $question;
            }
            if (4 === \count($kept)) {
                break;
            }
        }

        return $kept;
    }

    /**
     * What a question can name: the indicators of the data, and how many places they cover.
     *
     * @return array{indicators: list<string>, countries: int, cities: int, sample: list<string>}
     */
    public function knows(): array
    {
        $locale = $this->translator->getLocale();
        $indicators = $places = [];
        foreach ($this->catalog->all() as $entry) {
            $indicators[] = $entry->titleIn($locale);
            foreach ($entry->geo as $geo) {
                $places[$geo] = true;
            }
        }
        $codes = array_map(strval(...), array_keys($places));
        $cities = array_values(array_filter($codes, Places::isCity(...)));

        return [
            'indicators' => $indicators,
            'countries' => \count(array_filter($codes, Places::isCountry(...))),
            'cities' => \count($cities),
            'sample' => array_map($this->places->name(...), \array_slice($cities, 0, 8)),
        ];
    }

    /**
     * The words of the question that named nothing: neither a place, an indicator, a period, nor a
     * small word. Said with the answer: "beer" was not understood, the page is about the rest.
     *
     * @param list<string>       $places
     * @param list<CatalogEntry> $entries
     * @param int                $min     the shortest word looked at: two letters to see "UK" next to "or"
     *
     * @return list<string>
     */
    private function unused(string $text, array $places, array $entries, string $locale, int $min = 3): array
    {
        $used = [...self::SMALL_WORDS, ...self::TREND, ...self::COMPARE, ...self::LOWEST, ...array_map(self::plain(...), array_keys(self::SEASONS))];
        foreach ($places as $geo) {
            foreach ([$this->places->name($geo), $this->catalog->placeName($geo) ?? '', Places::countryName($geo)] as $name) {
                array_push($used, ...explode(' ', self::plain($name)));
            }
        }
        // The words of the indicators shown, and the other ways to say the same subject ("cheapest"
        // for "cheap"): the words of an indicator that is not shown were not understood.
        foreach ($entries as $entry) {
            array_push($used, ...explode(' ', self::plain(implode(' ', [...(self::WORDS[$entry->id] ?? []), ...(self::SURE[$entry->id] ?? []), $entry->title, $entry->titleIn($locale)]))));
            foreach ($this->catalog->all() as $other) {
                if ($other->theme === $entry->theme) {
                    array_push($used, ...explode(' ', self::plain(implode(' ', self::WORDS[$other->id] ?? []))));
                }
            }
        }
        array_push($used, ...$this->monthWords($locale));
        $left = [];
        foreach (explode(' ', trim($text)) as $word) {
            if ('' !== $word && 1 !== preg_match('/^[\d-]+$/', $word) && mb_strlen($word) >= $min && !\in_array($word, $used, true)) {
                $left[$word] = true;
            }
        }

        return \array_slice(array_map(strval(...), array_keys($left)), 0, 5);
    }

    /**
     * @return list<string> the names of the months, whole and short, in the page's language and in English
     */
    private function monthWords(string $locale): array
    {
        $words = [];
        foreach (array_unique([$locale, 'en']) as $language) {
            foreach (range(1, 12) as $number) {
                $words[] = self::plain(PageComposer::monthName(\sprintf('%02d', $number), $language));
                $words[] = self::plain(rtrim(PageComposer::monthName(\sprintf('%02d', $number), $language, short: true), '.'));
            }
        }

        return $words;
    }

    /**
     * A name the question gives that the data does not know ("Tokyo"): a word with a capital, after
     * the first, that is neither a place of the data, a month, a word of an indicator, nor a small
     * word. A name typed without its capital cannot be told from a word: this catches the careful
     * question only. Not every such word is a place ("Christmas"): it is refused in a comparison,
     * where a missing term changes the question, and said as not understood otherwise.
     */
    private function strangerIn(string $question, string $locale): ?string
    {
        $known = [...self::SMALL_WORDS, ...self::TREND, ...self::COMPARE, 'union', 'ai', 'ia', 'figure', 'eurostat', ...$this->monthWords($locale)];
        foreach (array_keys($this->names($locale)) as $name) {
            array_push($known, ...explode(' ', (string) $name));
        }
        foreach ($this->catalog->all() as $entry) {
            array_push($known, ...explode(' ', self::plain(implode(' ', [...(self::WORDS[$entry->id] ?? []), ...(self::SURE[$entry->id] ?? []), $entry->titleIn($locale), $entry->title]))));
        }
        preg_match_all('/(?<=[\p{L}\p{N},;:]\s)\p{Lu}\p{Ll}{2,}/u', $question, $names);
        foreach ($names[0] as $name) {
            if (!\in_array(self::plain($name), $known, true)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Every place of the catalog by its plain name, in the page's language and in English; the
     * longest names first ("Las Palmas" before "Palma").
     *
     * @return array<string, string>
     */
    private function names(string $locale): array
    {
        if (!isset($this->names[$locale])) {
            $names = [];
            foreach ($this->catalog->all() as $entry) {
                foreach ($entry->geo as $geo) {
                    foreach ([$this->places->name($geo), $this->catalog->placeName($geo) ?? '', Places::countryName($geo)] as $name) {
                        if ('' !== $name && $name !== $geo) {
                            $names[self::plain($name)] ??= $geo;
                        }
                    }
                }
            }
            // The names people use that the sources do not.
            foreach (self::OTHER_NAMES as $name => $geo) {
                if (\in_array($geo, $names, true)) {
                    $names[$name] ??= $geo;
                }
            }
            uksort($names, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
            $this->names[$locale] = $names;
        }

        return $this->names[$locale];
    }

    /**
     * The places named, in the order of the question.
     *
     * @return list<string>
     */
    private function placesIn(string $question, string $text): array
    {
        $found = [];
        foreach ($this->names($this->translator->getLocale()) as $name => $geo) {
            $name = (string) $name;
            $at = mb_strpos($text, ' '.$name.' ');
            if (false === $at) {
                continue;
            }
            // A name that is also a word ("Nice", "Split") is a place only with its capital, and not
            // as the first word of the question ("Nice weather in Lisbon?").
            if (\in_array($name, self::ALSO_WORDS, true) && 1 !== preg_match('/(?<=[\p{L}\p{N},;:]\s)'.preg_quote(mb_convert_case($name, \MB_CASE_TITLE), '/').'(?![\p{L}])/u', $question)) {
                continue;
            }
            $found[$geo] ??= $at;
            // The name is read once: "Palma" is not found again inside "Las Palmas".
            $text = str_replace(' '.$name.' ', ' '.str_repeat('_', mb_strlen($name)).' ', $text);
        }
        asort($found);

        return \array_slice(array_map(strval(...), array_keys($found)), 0, self::MAX_PLACES);
    }

    /**
     * @return array{list<string>, ?string, ?string} the months named ("09"), in the order of the year; the first year and the last
     */
    private function periodIn(string $text, string $locale): array
    {
        $months = [];
        foreach (array_unique([$locale, 'en']) as $language) {
            foreach (range(1, 12) as $number) {
                $code = \sprintf('%02d', $number);
                $whole = self::plain(PageComposer::monthName($code, $language));
                $short = self::plain(rtrim(PageComposer::monthName($code, $language, short: true), '.'));
                // A short or an ambiguous name counts only after "in" or "en": "may" is a verb, "mar" a sea.
                $sure = mb_strlen($whole) >= 4 && 'may' !== $whole && str_contains($text, ' '.$whole.' ');
                $after = 1 === preg_match('/ (?:in|en) (?:'.preg_quote($whole, '/').'|'.preg_quote($short, '/').') /u', $text);
                if ($sure || $after) {
                    $months[$code] = true;
                }
            }
        }
        // A month written as the data writes it ("2024-03") is that month of that year.
        if (1 === preg_match('/ ((?:19|20)\d{2})-(0[1-9]|1[0-2]) /', $text, $written)) {
            $months = [$written[2] => true] + $months;
            $text = str_replace(' '.$written[0], ' '.$written[1].' ', $text);
        }
        preg_match_all('/(?<![\d.,-])(19|20)\d{2}(?![\d.,])/', $text, $years);
        $years = array_values(array_unique($years[0]));
        sort($years);
        $trend = [] !== array_filter(self::TREND, static fn (string $word): bool => str_contains($text, ' '.$word.' '));
        $from = $to = null;
        if (\count($years) >= 2) {
            [$from, $to] = [$years[0], $years[\count($years) - 1]];
        } elseif (1 === \count($years)) {
            // "since 2015" is a span to the latest year; "in 2015" is that year alone.
            $trend ? $from = $years[0] : $to = $years[0];
        }

        return [array_map(strval(...), array_keys($months)), $from, $to];
    }

    /**
     * The indicators named, best first: by the words of the shipped catalog, or by the words of
     * the title for a dataset added on this machine. A longer word or phrase counts more.
     *
     * @param list<string> $places the places the question names: a file of the visitor answers for its own
     *
     * @return array{list<CatalogEntry>, list<array{CatalogEntry, CatalogEntry}>, bool, list<string>} the indicators; the pairs shown together because the question does not say which; whether only vague words named them; the vague words that named nothing
     */
    private function indicatorsIn(string $text, bool $withMonth, array $places = []): array
    {
        $locale = $this->translator->getLocale();
        // An indicator named by its whole title, of two words or more: the questions the page itself
        // writes. Every title named is kept, but one that a longer one holds.
        $named = array_values(array_filter($this->catalog->all(), static fn (CatalogEntry $entry): bool => substr_count(self::plain($entry->title), ' ') >= (isset(self::WORDS[$entry->id]) ? 1 : 2) && (str_contains($text, ' '.self::plain($entry->titleIn($locale)).' ') || str_contains($text, ' '.self::plain($entry->title).' '))));
        if ([] !== $named) {
            $named = array_values(array_filter($named, static fn (CatalogEntry $entry): bool => [] === array_filter($named, static fn (CatalogEntry $other): bool => $other !== $entry && str_contains(self::plain($other->title), self::plain($entry->title)))));

            return [\array_slice($named, 0, self::MAX_INDICATORS), [], false, []];
        }
        $has = static fn (string $word): bool => str_contains($text, ' '.self::plain($word).' ');
        $scores = $found = [];
        foreach ($this->catalog->all() as $entry) {
            $words = self::WORDS[$entry->id] ?? array_values(array_filter(
                preg_split('/[^\p{L}]+/u', self::plain($entry->title)) ?: [],
                static fn (string $word): bool => mb_strlen($word) >= 4 && !\in_array($word, self::EMPTY_WORDS, true),
            ));
            $score = 0;
            $phrase = $specific = false;
            foreach ($words as $word) {
                if ($has($word)) {
                    $found[$entry->id][] = self::plain($word);
                    $score += mb_strlen($word);
                    $phrase = $phrase || 1 === preg_match('/[\s-]/', $word);
                    $specific = $specific || !\in_array($word, self::VAGUE, true);
                }
            }
            if ($score > 0) {
                $scores[$entry->id] = [$score, $entry, $phrase, $specific];
            }
        }
        // A word of the visitor's own file is about that file: "the most visitors" of a file of shops
        // is not the tourism of Europe.
        $own = array_filter($scores, static fn (array $score): bool => FileImporter::PROVIDER === $score[1]->provider);
        if ([] !== $own && \count($own) < \count($scores)) {
            // Only when a word of the question is the file's alone ("shop"): "visitors" by itself
            // stays the tourism of the data.
            $shared = array_merge(...array_values(array_diff_key($found, $own)));
            $sure = array_filter($own, static fn (string $id): bool => [] !== array_diff($found[$id], $shared), \ARRAY_FILTER_USE_KEY);
            $mine = array_filter($places, Places::isOwn(...));
            $scores = match (true) {
                // A word or a place of the file alone: the file.
                [] !== $sure, [] !== $mine => $own,
                // Places of the public data: not the file.
                [] !== $places => array_diff_key($scores, $own),
                // Neither: both are shown, each says what it counts.
                default => $scores,
            };
            $found = array_intersect_key($found, $scores);
        }
        // A word read inside the longer words of another subject names nothing of its own: "prices"
        // in "house prices" is not the price of a meal, "population" in "relative to its population"
        // is not the number of inhabitants.
        foreach ($found as $id => $words) {
            foreach ($found as $other => $longer) {
                if ($other !== $id && isset($scores[$other]) && [] === array_filter($words, static fn (string $word): bool => [] === array_filter($longer, static fn (string $long): bool => $long !== $word && str_contains(' '.$long.' ', ' '.$word.' ')))) {
                    unset($scores[$id]);
                    continue 2;
                }
            }
        }
        // The index of house prices says where they rose, not where a home is cheap: with a word
        // that asks for a level, it would answer another question.
        if (isset($scores['house_prices']) && [] !== array_filter(self::LEVEL, $has)) {
            unset($scores['house_prices']);
        }
        // "Waste per inhabitant" is about waste: the tourism intensity needs a word of its own.
        $per = array_map(self::plain(...), self::PER);
        if (isset($scores['tourism_intensity']) && [] === array_diff($found['tourism_intensity'], $per) && [] !== array_filter($scores, static fn (array $score): bool => Theme::Other === $score[1]->theme) && 1 === \count(array_filter($scores, static fn (array $score): bool => Theme::Crowds === $score[1]->theme))) {
            unset($scores['tourism_intensity']);
        }
        // A price word with a hotel word is the price of hotels: the index of restaurants and
        // hotels, not the nights spent in hotels.
        $hotelPrice = null !== ($index = $this->catalog->get('prices_restaurants_hotels')) && [] !== array_filter(self::PRICE, $has) && [] !== array_filter(self::SURE['nights_hotels'], $has);
        if ($hotelPrice) {
            $scores['prices_restaurants_hotels'] = [100, $index, true, true];
            unset($scores['nights_hotels'], $scores['nights_total'], $scores['prices_food']);
        }
        // A vague word next to a word of another subject names nothing: "cheap flights" is about
        // flights, and the data has no price of a flight. The vague word is said as not understood.
        $dropped = [];
        $themes = array_map(static fn (array $score): string => $score[1]->theme->value, array_filter($scores, static fn (array $score): bool => $score[3]));
        if ([] !== $themes) {
            foreach ($scores as $id => [, $entry, , $specific]) {
                if (!$specific) {
                    unset($scores[$id]);
                    if (!\in_array($entry->theme->value, $themes, true)) {
                        array_push($dropped, ...array_filter(self::VAGUE, static fn (string $word): bool => $has($word) && \in_array($word, self::WORDS[$entry->id] ?? [], true)));
                    }
                }
            }
        }
        // A phrase says more than a word: "tourists per inhabitant" is the intensity, not the nights
        // "tourists" alone would give; "share of trains" is the share, not the passengers.
        foreach ($scores as $id => [, $entry, $phrase]) {
            if ($phrase) {
                foreach ($scores as $other => [, $rival, $rivalPhrase]) {
                    // Not among the subjects that have no theme: "house prices and inflation" are two.
                    if ($other !== $id && !$rivalPhrase && $rival->theme === $entry->theme && Theme::Other !== $entry->theme) {
                        unset($scores[$other]);
                    }
                }
            }
        }
        // A word that says which of a pair is meant leaves the other out.
        $said = static fn (string $which): bool => [] !== array_filter(self::SURE[$which], $has);
        foreach (self::TWINS as $id => $twin) {
            if (isset($scores[$id], $scores[$twin])) {
                if ($said($twin) && !$said($id)) {
                    unset($scores[$id]);
                } elseif ($said($id) && !$said($twin)) {
                    unset($scores[$twin]);
                }
            }
        }
        // Tourism by month only when the question names a month or a season; hotels alone when it says hotels.
        if (isset($scores['nights_total']) && $withMonth && null !== ($monthly = $this->catalog->get('nights_monthly')) && !isset($scores['nights_monthly'])) {
            $scores['nights_monthly'] = [$scores['nights_total'][0] + 1, $monthly, false, $scores['nights_total'][3]];
            unset($scores['nights_total']);
        }
        if (isset($scores['nights_hotels'], $scores['nights_total']) && $said('nights_hotels')) {
            unset($scores['nights_total']);
        }
        $twins = [];
        foreach (self::TWINS as $id => $twin) {
            $other = $this->catalog->get($twin);
            if (isset($scores[$id]) && !isset($scores[$twin]) && null !== $other && !$said($id) && !$said($twin) && !($hotelPrice && 'prices_restaurants_hotels' === $id)) {
                $scores[$twin] = [$scores[$id][0] - 1, $other, false, $scores[$id][3]];
                $twins[] = [$scores[$id][1], $other];
            }
        }
        uasort($scores, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $scores = \array_slice($scores, 0, self::MAX_INDICATORS, true);
        $entries = array_column($scores, 1);

        return [
            $entries,
            array_values(array_filter($twins, static fn (array $pair): bool => \in_array($pair[0], $entries, true) && \in_array($pair[1], $entries, true))),
            [] !== $scores && [] === array_filter($scores, static fn (array $score): bool => $score[3]),
            array_values(array_unique($dropped)),
        ];
    }

    /** Lower case, without accents, every sign but letters, digits and hyphens as a space. */
    private static function plain(string $text): string
    {
        $text = mb_strtolower((string) \Normalizer::normalize($text, \Normalizer::FORM_KD));
        $text = (string) preg_replace('/\p{M}/u', '', $text);

        return trim((string) preg_replace('/[^\p{L}\p{N}-]+/u', ' ', $text));
    }
}
