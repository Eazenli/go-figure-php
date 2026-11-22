<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Import\ImportStore;
use App\Layout\Constraint\FreeText;
use App\Layout\Constraint\NoDigitsValidator;
use App\Layout\Constraint\NumberWords;
use App\Layout\Constraint\PeriodCode;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\VarExporter\VarExporter;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The catalog entries the model may name. A dataset that is not here cannot be proven.
 *
 * The entries are read and checked once, then cached like the routes: in debug mode, editing,
 * adding or removing a file rebuilds the cache; in production, it is built on the first request
 * and kept. A broken entry is caught before that: the tests load every shipped file.
 *
 * The datasets imported on this machine (the extended mode) come after the shipped ones: read from
 * their store and checked again by the same rules at each request (a worker that serves several
 * requests resets them).
 */
final class Catalog implements ResetInterface
{
    /** @var array<string, CatalogEntry>|null */
    private ?array $entries = null;
    /** @var array<string, CatalogEntry>|null */
    private ?array $imports = null;
    private ?string $importsVersion = null;
    /** @var list<array{string, string, string}> the cities whose weather was added on this machine */
    private array $cities = [];
    /** @var array<string, string>|null */
    private ?array $names = null;

    public function __construct(
        private readonly CatalogLoader $loader,
        #[Autowire('%app.catalog_dir%')]
        private readonly string $dir,
        #[Autowire('%kernel.cache_dir%')]
        private readonly string $cacheDir,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
        private readonly ImportStore $imported = new ImportStore(''),
    ) {
    }

    /** Forget what was read: an import has just been saved, or a new request starts. */
    public function reset(): void
    {
        $this->imports = null;
        $this->names = null;
    }

    public function get(string $id): ?CatalogEntry
    {
        return $this->all()[$id] ?? null;
    }

    /** Whether some entry has this place, e.g. "EU27_2020". */
    public function knowsPlace(string $geo): bool
    {
        foreach ($this->all() as $entry) {
            if ($entry->covers($geo)) {
                return true;
            }
        }

        return false;
    }

    /** The name an entry gives a place ICU does not know, e.g. "Seville" for ES004C; null otherwise. */
    public function placeName(string $geo): ?string
    {
        // The loader makes sure no two entries name a place differently.
        $this->names ??= array_merge(...array_values(array_map(static fn (CatalogEntry $entry): array => $entry->names, $this->all())));

        return $this->names[$geo] ?? null;
    }

    /**
     * Whether some entry has such a period: a year within an annual or monthly entry ("2019"), a
     * month within a monthly entry ("2019-07"), or a month of the year if an entry is a climate
     * normal ("M09"). A period on a page is written by the model; this keeps it from carrying any
     * other number.
     */
    public function knowsPeriod(string $period): bool
    {
        if (1 !== preg_match(PeriodCode::PATTERN, $period)) {
            return false;
        }

        foreach ($this->all() as $entry) {
            $time = $entry->time;
            $known = match (true) {
                str_starts_with($period, 'M') => Granularity::MonthOfYear === $time->granularity,
                7 === \strlen($period) => Granularity::Monthly === $time->granularity && $time->from <= $period && $period <= $time->to,
                default => Granularity::MonthOfYear !== $time->granularity && substr($time->from, 0, 4) <= $period && $period <= substr($time->to, 0, 4),
            };
            if ($known) {
                return true;
            }
        }

        return false;
    }

    /**
     * The shipped entries, then the imported ones.
     *
     * @return array<string, CatalogEntry> keyed by id
     *
     * @throws InvalidCatalog
     */
    public function all(): array
    {
        // Read again when the store changed: an import made through the web reaches a long-running
        // STDIO MCP server too.
        $version = $this->imported->version();
        if (null === $this->imports || $version !== $this->importsVersion) {
            $this->importsVersion = $version;
            $this->names = null;
            $this->imports = [];
            $this->cities = $this->imported->cities();
            foreach ($this->imported->entries() as $data) {
                try {
                    $entry = $this->loader->fromArray($data);
                } catch (InvalidCatalog) {
                    continue; // written by this server and checked then: only a change of the rules
                }
                if (ImportStore::owns($entry->id)) {
                    $this->imports[$entry->id] = $entry;
                }
            }
        }

        return $this->withCities($this->shipped()) + $this->imports;
    }

    /**
     * The climate normals of Open-Meteo, with the cities whose weather was added on this machine.
     *
     * @param array<string, CatalogEntry> $entries
     *
     * @return array<string, CatalogEntry>
     */
    private function withCities(array $entries): array
    {
        $cities = $this->cities;
        if ([] === $cities) {
            return $entries;
        }
        foreach ($entries as $id => $entry) {
            if ('open_meteo' === $entry->provider && Granularity::MonthOfYear === $entry->time->granularity) {
                foreach ($cities as [$code, $name, $place]) {
                    $entry = $entry->withPlace($code, $name, $place);
                }
                $entries[$id] = $entry;
            }
        }

        return $entries;
    }

    /**
     * The entries of catalog/ only: what the example questions of /ask were recorded with.
     *
     * @return array<string, CatalogEntry> keyed by id, in id order
     *
     * @throws InvalidCatalog
     */
    public function shipped(): array
    {
        if (null !== $this->entries) {
            return $this->entries;
        }

        $cache = new ConfigCache($this->cacheDir.'/catalog.php', $this->debug);
        if (!$cache->isFresh()) {
            $entries = $this->loader->load($this->dir);
            $cache->write('<?php return '.VarExporter::export($entries).";\n", [
                new DirectoryResource($this->dir, '/\.yaml$/'),
                // A change in how entries are read or built must not serve the old cache.
                ...array_map(
                    static fn (string $class): FileResource => new FileResource((string) new \ReflectionClass($class)->getFileName()),
                    [CatalogLoader::class, CatalogEntry::class, TimeCoverage::class, Attribution::class, Places::class, NoDigitsValidator::class, FreeText::class, NumberWords::class],
                ),
            ]);
        }

        return $this->entries = require $cache->getPath();
    }
}
