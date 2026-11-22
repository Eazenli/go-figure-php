<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Layout\Constraint\FreeText;
use App\Layout\Constraint\NoDigits;
use App\Layout\Op;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads catalog/*.yaml and checks every entry against the format. Unknown keys are
 * errors too, so a typo such as "pitfals" is caught rather than ignored.
 */
final readonly class CatalogLoader
{
    private const string ID = '/^[a-z][a-z0-9_]{0,39}$/D';
    private const string PLACE = '/^[A-Z][A-Z0-9_]{1,15}$/D';
    private const string YEAR = '/^\d{4}$/D';
    private const string MONTH = '/^\d{4}-(0[1-9]|1[0-2])$/D';
    private const string NORMAL = '/^\d{4}-\d{4}$/D';
    /**
     * The parts of a Eurostat unit code that carry a scale, anywhere in the code ("CP_MEUR",
     * "CLV15_MEUR", "THS_PAS", "P_THAB"), and the word their label must hold.
     */
    public const array SCALES = [
        '/^(THS|KTOE|KT|THAB)/' => 'thousand',
        '/^(MIO|MHAB|M(EUR|NAC|PPS|ECU|USD|TOE)$)/' => 'million',
        '/^(BN|BIO|MRD|B(EUR|NAC|PPS)$)/' => 'billion',
        '/^HTHAB$/' => 'hundred thousand',
    ];

    public function __construct(
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @return array<string, CatalogEntry> keyed by id, in id order
     *
     * @throws InvalidCatalog with every error of every file
     */
    public function load(string $dir): array
    {
        if (!is_dir($dir)) {
            throw new InvalidCatalog([\sprintf('No catalog directory at %s.', $dir)]);
        }

        $entries = [];
        $errors = [];
        foreach (glob($dir.'/*.yaml') ?: [] as $file) {
            $name = basename($file);
            try {
                $data = Yaml::parseFile($file);
            } catch (ParseException $e) {
                $errors[] = \sprintf('%s: %s', $name, $e->getMessage());
                continue;
            }

            $violations = $this->validator->validate($data, self::format());
            foreach ($violations as $violation) {
                $errors[] = \sprintf('%s: %s: %s', $name, self::field($violation->getPropertyPath()), $violation->getMessage());
            }
            if (0 < \count($violations)) {
                continue;
            }

            /** @var array<string, mixed> $data checked by the format above */
            if ($data['id'] !== basename($file, '.yaml')) {
                $errors[] = \sprintf('%s: id: "%s" must match the file name.', $name, $data['id']);
                continue;
            }
            $entries[$data['id']] = self::entry($data);
        }

        // A place has one name on every page: two entries must not name it differently.
        $named = [];
        foreach ($entries as $id => $entry) {
            foreach ($entry->names as $geo => $name) {
                if (isset($named[$geo]) && $named[$geo][1] !== $name) {
                    $errors[] = \sprintf('%s.yaml: places.%s: "%s" is "%s" in %s.yaml: use one name.', $id, $geo, $name, $named[$geo][1], $named[$geo][0]);
                }
                $named[$geo] ??= [$id, $name];
            }
        }

        if ([] !== $errors) {
            throw new InvalidCatalog($errors);
        }
        ksort($entries);

        return $entries;
    }

    private static function format(): Constraint
    {
        $text = [new Assert\Type('string'), new Assert\NotBlank()];
        $texts = new Assert\Collection(fields: ['en' => $text, 'fr' => new Assert\Optional($text)]);
        $place = [new Assert\Type('string'), new Assert\Regex(self::PLACE, message: 'This is not a place code such as FR or EU27_2020.')];
        // Shown on every page, as titles are: the rule of the page applies.
        $shown = [...$text, new NoDigits(groups: null, message: 'No digits, numbers in words or number signs: this text is shown on the page, where every number is a proven figure.')];
        $link = [new Assert\Type('string'), new Assert\Regex('#^https://[^\s()<>]+$#D', message: 'This is not an https URL.')];

        return new Assert\Sequentially([
            // An empty file parses to null, which Collection lets through.
            new Assert\NotNull(message: 'This file is empty.'),
            new Assert\Type('array'),
            new Assert\Collection(fields: [
                'id' => [new Assert\Type('string'), new Assert\Regex(self::ID, message: 'Use lower case letters, digits and "_", at most 40.')],
                'title' => new Assert\Collection(fields: [
                    // The composer shows it as a page title, and a model copies it into its own: the
                    // rule of the page applies. A scale goes in unit.label ("nights per thousand inhabitants").
                    'en' => [...$text, new NoDigits(groups: null, message: 'No digits, numbers in words or number signs in a title: on a page, every number is a proven figure. Put a scale in unit.label.')],
                    'fr' => new Assert\Optional([...$text, new NoDigits(groups: null, message: 'No digits, numbers in words or number signs in a title: on a page, every number is a proven figure. Put a scale in unit.label.')]),
                ]),
                'source' => new Assert\Collection(fields: [
                    'provider' => [new Assert\Type('string'), new Assert\Regex(self::ID)],
                    'dataset' => $text,
                    'url' => [new Assert\Type('string'), new Assert\Regex('#^https://\S+$#D', message: 'This is not an https URL.')],
                    'licence' => $text,
                    // What the source asks to show next to its figures, e.g. Open-Meteo's link.
                    'attribution' => new Assert\Optional([
                        new Assert\Type('list'),
                        new Assert\Count(min: 1),
                        new Assert\All(new Assert\Collection(fields: ['text' => $shown, 'url' => new Assert\Optional($link)])),
                    ]),
                ]),
                'dimensions' => new Assert\Optional(new Assert\All([new Assert\Type(['string', 'int']), new Assert\NotBlank()])),
                'geo' => [new Assert\Type('list'), new Assert\Count(min: 1), new Assert\Unique(), new Assert\All($place)],
                'geo_map' => new Assert\Optional([new Assert\Type('array'), new Assert\All($text)]),
                // The English names of the places ICU does not know: cities (ES004C: Seville).
                // A city's name follows the rule of shown text; a place of a deposited file has its own (checkPlaceNames).
                'places' => new Assert\Optional([new Assert\Type('array'), new Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]),
                'time' => [
                    new Assert\Collection(fields: [
                        'granularity' => new Assert\Choice(choices: array_column(Granularity::cases(), 'value')),
                        'from' => new Assert\Optional(new Assert\Type(['string', 'int'])),
                        'to' => new Assert\Optional(new Assert\Type(['string', 'int'])),
                        'normal' => new Assert\Optional(new Assert\Type('string')),
                    ]),
                    new Assert\Callback(self::checkTime(...)),
                ],
                'unit' => [
                    new Assert\Collection(fields: [
                        'code' => [new Assert\Type('string'), new Assert\Regex('/^[A-Za-z][A-Za-z0-9_]*$/D')],
                        'label' => $text,
                    ]),
                    new Assert\Callback(self::checkScale(...)),
                ],
                'scope' => $texts,
                // The question of the traveller it answers: /compare groups its charts by theme.
                'theme' => new Assert\Choice(choices: array_column(Theme::cases(), 'value')),
                // How we compute the value from what the source publishes, when we do.
                'method' => new Assert\Optional($texts),
                'pitfalls' => new Assert\Optional([new Assert\Type('list'), new Assert\All($text)]),
                'operations' => [
                    new Assert\Type('list'),
                    new Assert\Count(min: 1),
                    new Assert\Unique(),
                    new Assert\Choice(choices: array_column(Op::cases(), 'value'), multiple: true),
                ],
            ]),
            new Assert\Callback(self::checkPlaceUrl(...)),
            new Assert\Callback(self::checkPlaceNames(...)),
        ]);
    }

    /**
     * One entry given as data rather than as a file (an imported dataset), checked by
     * the same rules as a file of catalog/.
     *
     * @param array<mixed> $data
     *
     * @throws InvalidCatalog with every error of the entry
     */
    public function fromArray(array $data): CatalogEntry
    {
        $errors = [];
        foreach ($this->validator->validate($data, self::format()) as $violation) {
            $errors[] = \sprintf('%s: %s', self::field($violation->getPropertyPath()), $violation->getMessage());
        }
        if ([] !== $errors) {
            throw new InvalidCatalog($errors);
        }

        /** @var array<string, mixed> $data checked by the format above */
        return self::entry($data);
    }

    /**
     * A unit code that counts in thousands or millions (Eurostat's THS_PAS, MIO_PKM, P_THAB) needs a
     * label that says so: the page shows the label next to every figure, and without the scale a
     * value would read a thousand times too small.
     */
    private static function checkScale(mixed $unit, ExecutionContextInterface $context): void
    {
        if (!\is_array($unit) || !\is_string($unit['code'] ?? null) || !\is_string($unit['label'] ?? null)) {
            return; // reported by the Collection constraint
        }
        foreach (self::SCALES as $pattern => $word) {
            $scaled = [] !== preg_grep($pattern, explode('_', $unit['code']));
            if ($scaled && 1 !== preg_match('/\b'.$word.'\b/i', $unit['label'])) {
                $context->buildViolation(\sprintf('The unit %s has a scale: the label must say "%s", as in "%s nights".', $unit['code'], $word, $word))
                    ->atPath('[label]')
                    ->addViolation();
            }
        }
    }

    /**
     * The first and last periods must match the granularity; a climate normal has neither.
     */
    private static function checkTime(mixed $time, ExecutionContextInterface $context): void
    {
        $granularity = Granularity::tryFrom(\is_array($time) && \is_string($time['granularity'] ?? null) ? $time['granularity'] : '');
        if (null === $granularity) {
            return; // reported by the Collection constraint
        }
        // YAML reads 2008 as an integer; anything else that is not a string is reported by the
        // Collection constraint.
        $period = static fn (string $key): string => \is_int($time[$key] ?? null) || \is_string($time[$key] ?? null) ? (string) $time[$key] : '';

        if (Granularity::MonthOfYear === $granularity) {
            if (!preg_match(self::NORMAL, $period('normal'))) {
                $context->buildViolation('A climate normal needs its reference period, e.g. "1991-2020".')->atPath('[normal]')->addViolation();
            }
            foreach (['from', 'to'] as $key) {
                if (\array_key_exists($key, $time)) {
                    $context->buildViolation('A climate normal covers M01 to M12: remove this field.')->atPath("[$key]")->addViolation();
                }
            }

            return;
        }

        [$pattern, $expected] = Granularity::Annual === $granularity ? [self::YEAR, 'a year such as 2008'] : [self::MONTH, 'a month such as 2008-01'];
        foreach (['from', 'to'] as $key) {
            if (!preg_match($pattern, $period($key))) {
                $context->buildViolation(\sprintf('This is not %s.', $expected))->atPath("[$key]")->addViolation();
            }
        }
        if (\array_key_exists('normal', $time)) {
            $context->buildViolation('Only a climate normal (month_of_year) has a reference period.')->atPath('[normal]')->addViolation();
        }
        if ($period('from') > $period('to')) {
            $context->buildViolation('The first period comes after the last one.')->atPath('[from]')->addViolation();
        }
    }

    /**
     * A source URL that varies by place needs the source's code for every place.
     *
     * @param array<string, mixed> $entry
     */
    private static function checkPlaceUrl(array $entry, ExecutionContextInterface $context): void
    {
        if (str_contains($entry['source']['url'], '{iso3}')) {
            $context->buildViolation(\sprintf('The placeholder of a place is now %s.', CatalogEntry::PLACE))->atPath('[source][url]')->addViolation();

            return;
        }
        if (!str_contains($entry['source']['url'], CatalogEntry::PLACE)) {
            return;
        }
        $missing = array_diff($entry['geo'], array_keys($entry['geo_map'] ?? []));
        if ([] !== $missing) {
            $context->buildViolation(\sprintf('The source URL contains %s: add these places to geo_map: %s.', CatalogEntry::PLACE, implode(', ', $missing)))
                ->atPath('[geo_map]')
                ->addViolation();
        }
    }

    /**
     * A city has no name in ICU: the entry gives it. A name is only for a place of the entry.
     *
     * @param array<string, mixed> $entry
     */
    private static function checkPlaceNames(array $entry, ExecutionContextInterface $context): void
    {
        $names = $entry['places'] ?? [];
        foreach ($names as $code => $name) {
            if (!\is_string($name)) {
                continue;
            }
            if (Places::isOwn((string) $code)) {
                if (1 !== preg_match(Places::OWN_NAME, $name)) {
                    $context->buildViolation('The name of a place of a file holds letters, digits and a few signs, forty characters at most.')->atPath('[places]['.$code.']')->addViolation();
                }
            } elseif (null !== FreeText::problem($name)) {
                $context->buildViolation('No digits, numbers in words or number signs: this text is shown on the page, where every number is a proven figure.')->atPath('[places]['.$code.']')->addViolation();
            }
        }
        $unnamed = array_filter($entry['geo'], static fn (string $geo): bool => (Places::isCity($geo) || Places::isOwn($geo)) && !isset($names[$geo]));
        if ([] !== $unnamed) {
            $context->buildViolation(\sprintf('A city needs its English name: add these places to places: %s.', implode(', ', $unnamed)))->atPath('[places]')->addViolation();
        }
        $keys = array_map(strval(...), array_keys($names));
        $strangers = array_diff($keys, $entry['geo']);
        if ([] !== $strangers) {
            $context->buildViolation(\sprintf('These places are not in geo: %s.', implode(', ', $strangers)))->atPath('[places]')->addViolation();
        }
        // A country keeps the name ICU gives it on every page: an entry cannot rename it.
        $notCities = array_filter(array_intersect($keys, $entry['geo']), static fn (string $geo): bool => !Places::isCity($geo) && !Places::isOwn($geo));
        if ([] !== $notCities) {
            $context->buildViolation(\sprintf('Only a city takes a name here: %s is named by the server.', implode(', ', $notCities)))->atPath('[places]')->addViolation();
        }
    }

    /**
     * @param array<string, mixed> $data an entry that passed the format
     */
    private static function entry(array $data): CatalogEntry
    {
        $time = $data['time'];
        $granularity = Granularity::from($time['granularity']);

        return new CatalogEntry(
            id: $data['id'],
            title: $data['title']['en'],
            provider: $data['source']['provider'],
            sourceDataset: $data['source']['dataset'],
            url: $data['source']['url'],
            licence: $data['source']['licence'],
            dimensions: array_map(strval(...), $data['dimensions'] ?? []),
            geo: $data['geo'],
            geoMap: $data['geo_map'] ?? [],
            time: Granularity::MonthOfYear === $granularity
                ? new TimeCoverage($granularity, 'M01', 'M12', $time['normal'])
                : new TimeCoverage($granularity, (string) $time['from'], (string) $time['to']),
            unitCode: $data['unit']['code'],
            unitLabel: $data['unit']['label'],
            scope: $data['scope']['en'],
            pitfalls: $data['pitfalls'] ?? [],
            operations: array_values(array_map(Op::from(...), $data['operations'])),
            theme: Theme::from($data['theme']),
            names: array_map(strval(...), $data['places'] ?? []),
            method: $data['method']['en'] ?? null,
            attributions: array_values(array_map(
                static fn (array $a): Attribution => new Attribution($a['text'], $a['url'] ?? null),
                $data['source']['attribution'] ?? [],
            )),
            titles: array_filter(['fr' => $data['title']['fr'] ?? null]),
            scopes: array_filter(['fr' => $data['scope']['fr'] ?? null]),
            methods: array_filter(['fr' => $data['method']['fr'] ?? null]),
        );
    }

    /** "[time][from]" becomes "time.from". */
    private static function field(string $propertyPath): string
    {
        return '' === $propertyPath ? '(entry)' : str_replace(['][', '[', ']'], ['.', '', ''], $propertyPath);
    }
}
