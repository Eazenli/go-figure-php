<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Catalog\Theme;
use App\Import\CityWeather;
use App\Import\DatasetNotAllowed;
use App\Import\DatasetNotByPlace;
use App\Import\DatasetNotFound;
use App\Import\DimensionChoice;
use App\Import\EurostatApi;
use App\Import\EurostatTools;
use App\Import\EurostatUnavailable;
use App\Import\ImportRefused;
use App\Import\Importer;
use App\Import\ImportStore;
use App\Import\Proposal;
use App\Import\WorldBankImporter;
use App\Import\WorldBankUnavailable;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The extended mode through MCP: the assistant of the visitor looks for a Eurostat dataset, reads
 * its dimensions and adds it, as the agent of /import does. The client asks the visitor before each
 * call; the server checks every code and reads every value from Eurostat itself.
 */
final readonly class ImportTools
{
    public function __construct(
        private EurostatTools $tools,
        private EurostatApi $eurostat,
        private Importer $importer,
        private CityWeather $weather,
        private WorldBankImporter $worldBankImporter,
        #[Autowire(service: 'limiter.import_all')]
        private RateLimiterFactoryInterface $importAllLimiter,
    ) {
    }

    #[McpTool(
        name: 'search_eurostat',
        title: 'Go Figure: look for a Eurostat dataset',
        description: 'Looks for Eurostat datasets whose title holds the words of the query (in English). Returns codes and titles, never values. Then call describe_eurostat_dataset with a code.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: true),
    )]
    public function search(
        #[Schema(description: 'A few English words, e.g. "arrivals tourist accommodation".', maxLength: 120)]
        string $query,
    ): CallToolResult {
        return new CallToolResult([new TextContent($this->tools->search(mb_substr($query, 0, 120)))]);
    }

    #[McpTool(
        name: 'describe_eurostat_dataset',
        title: 'Go Figure: read the dimensions of a Eurostat dataset',
        description: 'Lists the dimensions of a Eurostat dataset and their codes. To add it with add_dataset, choose one code for each of them: geo and time stay free.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: true),
    )]
    public function describe(
        #[Schema(description: 'A Eurostat dataset code, e.g. tour_occ_arnat.', pattern: '^[a-z][a-z0-9_]{2,39}$')]
        string $code,
    ): CallToolResult {
        return new CallToolResult([new TextContent($this->tools->describe($code))]);
    }

    /**
     * @param list<string> $dimensions
     * @param list<string> $pitfalls
     */
    #[McpTool(
        name: 'add_dataset',
        title: 'Go Figure: add a Eurostat dataset',
        description: 'Adds a Eurostat dataset (the economy, businesses, agriculture, energy, the environment, transport, tourism, prices, culture; no subject about people\'s lives such as health, migration or justice) to the catalog of this Go Figure, with one code for every dimension but geo and time. The unit uses the words of the dataset itself, without its scale. The server checks the codes and reads every value from Eurostat with the date of the import; then the dataset can be named in render_page like any other. The title, scope, unit and pitfalls are shown with the figures: no digits and no numbers in words.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true),
    )]
    public function add(
        #[Schema(description: 'The Eurostat dataset code, e.g. tour_occ_arnat.', pattern: '^[a-z][a-z0-9_]{2,39}$')]
        string $code,
        #[Schema(description: 'One "dimension=code" per dimension but geo and time, e.g. ["freq=A", "unit=NR", "c_resid=FOR"].', items: ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*=[A-Za-z0-9_.\-]+$'], maxItems: 12)]
        array $dimensions,
        #[Schema(description: 'A short title: what one figure counts. No digits.', maxLength: 80)]
        string $title,
        #[Schema(description: 'One or two sentences: what the figures count and leave out. No digits.', maxLength: 400)]
        string $scope,
        #[Schema(description: 'What one figure counts, after the number, without its scale: "arrivals", "passengers". The server adds "thousand" or "million" from the unit code.', maxLength: 60)]
        string $unit_label,
        #[Schema(description: 'The question of the traveller it answers, or "other" when it is not about travel.', enum: ['cost', 'crowds', 'weather', 'access', 'other'])]
        string $theme,
        #[Schema(description: 'At most three traps for a reader, each a short sentence. No digits.', items: ['type' => 'string', 'maxLength' => 200], maxItems: 3)]
        array $pitfalls = [],
    ): CallToolResult {
        if (!$this->importAllLimiter->create('all')->consume()->isAccepted()) {
            return self::error('Too many imports today on this Go Figure: try again tomorrow.');
        }
        $choices = [];
        foreach ($dimensions as $dimension) {
            [$name, $value] = array_pad(explode('=', (string) $dimension, 2), 2, '');
            $choices[] = new DimensionChoice(trim($name), trim($value));
        }
        $proposal = new Proposal(
            code: $code,
            dimensions: $choices,
            title: $title,
            scope: $scope,
            unitLabel: $unit_label,
            theme: Theme::tryFrom($theme) ?? Theme::Other,
            pitfalls: array_map(strval(...), $pitfalls),
        );

        try {
            $entry = $this->importer->import($proposal, $this->eurostat->structure($code));
        } catch (ImportRefused $e) {
            return self::error("Not added:\n- ".implode("\n- ", array_map(static fn (array $r): string => strtr($r[0], $r[1]), $e->reasons)));
        } catch (DatasetNotFound|DatasetNotByPlace|DatasetNotAllowed $e) {
            return self::error('Not added: '.$e->getMessage());
        } catch (EurostatUnavailable) {
            return self::error('Not added: Eurostat does not answer right now.');
        } catch (\Throwable) {
            return self::error('Not added: the import failed, nothing was kept.');
        }

        return new CallToolResult(
            content: [new TextContent(\sprintf(
                'Added `%s`: %s (%s; %d places, %s to %s). Name its cells in render_page like this: %s. Call describe_dataset for its places and pitfalls.',
                $entry->id,
                $entry->title,
                $entry->unitLabel,
                \count($entry->geo),
                $entry->time->from,
                $entry->time->to,
                json_encode(['type' => 'measured', 'dataset' => $entry->id, 'geo' => $entry->geo[0] ?? 'FR', 'time' => $entry->time->to], \JSON_UNESCAPED_SLASHES),
            ))],
            structuredContent: ['id' => $entry->id],
        );
    }

    #[McpTool(
        name: 'search_world_bank',
        title: 'Go Figure: look for a World Bank indicator',
        description: 'Looks for World Bank indicators (World Development Indicators: every country of the world, by year) whose name holds the words of the query (in English). Returns ids and names, never values. Then call add_world_bank_indicator with an id.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: true),
    )]
    public function searchWorldBank(
        #[Schema(description: 'A few English words, e.g. "forest area" or "renewable electricity".', maxLength: 120)]
        string $query,
    ): CallToolResult {
        return new CallToolResult([new TextContent($this->tools->searchWorldBank(mb_substr($query, 0, 120)))]);
    }

    #[McpTool(
        name: 'add_world_bank_indicator',
        title: 'Go Figure: add a World Bank indicator',
        description: 'Adds one World Bank indicator to the catalog of this Go Figure: the server reads every value, by country and year, from the World Bank itself, and writes the title and the unit from the World Bank\'s own words. The economy, energy, the environment, agriculture, trade, science; no subject about people\'s lives such as health or poverty. Then name its cells in render_page like any other dataset.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true),
    )]
    public function addWorldBankIndicator(
        #[Schema(description: 'The id of the indicator, as search_world_bank returns it, e.g. NY.GDP.MKTP.CD.', pattern: '^[A-Z][A-Z0-9]{1,5}(\.[A-Z0-9]{1,8}){1,6}$')]
        string $indicator,
        #[Schema(description: 'The question of the traveller it answers, or "other" when it is not about travel.', enum: ['cost', 'crowds', 'weather', 'access', 'other'])]
        string $theme = 'other',
    ): CallToolResult {
        if (!$this->importAllLimiter->create('all')->consume()->isAccepted()) {
            return self::error('Too many imports today on this Go Figure: try again tomorrow.');
        }
        try {
            $entry = $this->worldBankImporter->import($indicator, Theme::tryFrom($theme) ?? Theme::Other);
        } catch (ImportRefused $e) {
            return self::error("Not added:\n- ".implode("\n- ", array_map(static fn (array $r): string => strtr($r[0], $r[1]), $e->reasons)));
        } catch (DatasetNotFound) {
            return self::error('Not added: the World Bank has no such indicator among its World Development Indicators.');
        } catch (DatasetNotAllowed $e) {
            return self::error('Not added: '.$e->getMessage());
        } catch (WorldBankUnavailable) {
            return self::error('Not added: the World Bank does not answer right now.');
        } catch (\Throwable) {
            return self::error('Not added: the import failed, nothing was kept.');
        }

        return new CallToolResult(
            content: [new TextContent(\sprintf(
                'Added `%s`: %s (%s; %d countries, %s to %s). Name its cells in render_page like this: %s. Call describe_dataset for its places and pitfalls.',
                $entry->id,
                $entry->title,
                $entry->unitLabel,
                \count($entry->geo),
                $entry->time->from,
                $entry->time->to,
                json_encode(['type' => 'measured', 'dataset' => $entry->id, 'geo' => \in_array('FR', $entry->geo, true) ? 'FR' : ($entry->geo[0] ?? 'FR'), 'time' => $entry->time->to], \JSON_UNESCAPED_SLASHES),
            ))],
            structuredContent: ['id' => $entry->id],
        );
    }

    #[McpTool(
        name: 'add_city',
        title: 'Go Figure: add the weather of a city',
        description: 'Adds the weather of one more city to this Go Figure: one request to Open-Meteo (on the free quota of this machine, about a dozen cities a day), the monthly normals computed by the server. Call it without a city to list the cities that can be added; then name its cells in render_page with the datasets temp_max_city and rain_city.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true),
    )]
    public function addCity(
        #[Schema(description: 'A Eurostat city code taken from the list this tool returns when called without a city. Empty: return that list.', pattern: '^([A-Z]{2}[0-9]{3}C)?$')]
        string $city = '',
    ): CallToolResult {
        if ('' === $city) {
            $lines = [];
            foreach ($this->weather->addable() as $code => $place) {
                $lines[] = \sprintf('- `%s`: %s (%s)', $code, $place['name'], $place['country']);
            }

            return new CallToolResult([new TextContent([] === $lines ? ($this->weather->full() ? \sprintf('No city can be added: this Go Figure already keeps %d added cities.', ImportStore::MAX_CITIES) : 'No city can be added: every city of the list is in the catalog.') : "Cities whose weather can be added:\n\n".implode("\n", $lines))]);
        }
        if (!$this->importAllLimiter->create('all')->consume()->isAccepted()) {
            return self::error('Too many imports today on this Go Figure: try again tomorrow.');
        }
        try {
            $name = $this->weather->add($city);
        } catch (ImportRefused $e) {
            return self::error("Not added:\n- ".implode("\n- ", array_map(static fn (array $r): string => strtr($r[0], $r[1]), $e->reasons)));
        } catch (EurostatUnavailable) {
            return self::error('Not added: Open-Meteo does not answer right now.');
        } catch (\Throwable) {
            return self::error('Not added: the import failed, nothing was kept.');
        }

        return new CallToolResult(
            content: [new TextContent(\sprintf('Added %s (`%s`). Name its cells in render_page like this: %s (months of the year, M01 to M12; also in rain_city).%s', $name, $city, json_encode(['type' => 'measured', 'dataset' => 'temp_max_city', 'geo' => $city, 'time' => 'M09'], \JSON_UNESCAPED_SLASHES), $this->weather->offCentre($city) ? ' Tell the reader: the point of this city lies well outside its centre, so its weather can differ from the centre\'s.' : ''))],
            structuredContent: ['city' => $city],
        );
    }

    private static function error(string $text): CallToolResult
    {
        return new CallToolResult(content: [new TextContent($text)], isError: true);
    }
}
