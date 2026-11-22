<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Catalog\Catalog;
use App\Catalog\CatalogEntry;
use App\Catalog\Granularity;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * The catalog, as the model sees it: what it may name in a page. The catalog holds no value:
 * only what each dataset measures, where and when.
 */
final readonly class CatalogTools
{
    public function __construct(
        private Catalog $catalog,
    ) {
    }

    #[McpTool(
        name: 'list_datasets',
        title: 'Go Figure: list the datasets',
        description: 'Lists the datasets a Go Figure page may show: id, title, unit and periods. Call describe_dataset for the places, scope and pitfalls of one dataset before naming its cells in render_page.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function list(): CallToolResult
    {
        $lines = [];
        $datasets = [];
        foreach ($this->catalog->all() as $entry) {
            $lines[] = \sprintf('- `%s`: %s (%s; %s; %d places)', $entry->id, $entry->title, $entry->unitLabel, self::periods($entry), \count($entry->geo));
            $datasets[] = [
                'id' => $entry->id,
                'title' => $entry->title,
                'unit' => $entry->unitLabel,
                'granularity' => $entry->time->granularity->value,
                'from' => $entry->time->from,
                'to' => $entry->time->to,
                'places' => \count($entry->geo),
            ];
        }

        return new CallToolResult(
            content: [new TextContent("Datasets a page may show:\n\n".implode("\n", $lines)."\n\nCall describe_dataset with an id for its places, scope and pitfalls.")],
            structuredContent: ['datasets' => $datasets],
        );
    }

    #[McpTool(
        name: 'describe_dataset',
        title: 'Go Figure: describe a dataset',
        description: 'Describes one dataset: what it measures (scope), its places, its periods and how to write them, its pitfalls, and how to name one of its cells in render_page.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function describe(
        #[Schema(description: 'Dataset id, as given by list_datasets.')]
        string $id,
    ): CallToolResult {
        $entry = $this->catalog->get($id);
        if (null === $entry) {
            return new CallToolResult(
                content: [new TextContent(\sprintf('There is no dataset "%s". The datasets are: %s.', $id, implode(', ', array_keys($this->catalog->all()))))],
                isError: true,
            );
        }

        $example = self::example($entry);
        $text = self::brief($entry)."\n\n".'Name one of its cells in render_page like this: '.json_encode($example, \JSON_UNESCAPED_SLASHES).'. Not every place has every period (see the pitfalls): a cell the source does not have is refused on the page, never guessed.';

        return new CallToolResult(
            content: [new TextContent($text)],
            structuredContent: [
                'id' => $entry->id,
                'title' => $entry->title,
                'scope' => $entry->scope,
                'unit' => $entry->unitLabel,
                'granularity' => $entry->time->granularity->value,
                'from' => $entry->time->from,
                'to' => $entry->time->to,
                'places' => $entry->geo,
                // The names of the places ICU does not know, e.g. ES004C => Seville.
                'place_names' => (object) $entry->names,
                'method' => $entry->method,
                'pitfalls' => $entry->pitfalls,
                'source' => ['provider' => $entry->provider, 'dataset' => $entry->sourceDataset, 'licence' => $entry->licence],
                'example' => $example,
            ],
        );
    }

    /**
     * What a model needs to know of an entry to name its cells: scope, unit, periods, places,
     * source and pitfalls. Also the catalog part of the /ask prompt.
     */
    public static function brief(CatalogEntry $entry): string
    {
        $text = [
            \sprintf('# %s (`%s`)', $entry->title, $entry->id),
            'Scope: '.$entry->scope,
            ...(null === $entry->method ? [] : ['Computed by Go Figure from the source: '.$entry->method]),
            \sprintf('Unit: %s. Periods: %s.', $entry->unitLabel, self::periods($entry)),
            self::places($entry),
            \sprintf('Source: %s, dataset %s (%s).', $entry->provider, $entry->sourceDataset, $entry->licence),
            \sprintf('Calculations allowed: %s.', implode(', ', array_map(static fn ($op): string => $op->value, $entry->operations))),
        ];
        if ([] !== $entry->pitfalls) {
            $text[] = "Pitfalls:\n".implode("\n", array_map(static fn (string $p): string => '- '.$p, $entry->pitfalls));
        }
        $text[] = 'A cell of it: '.json_encode(self::example($entry), \JSON_UNESCAPED_SLASHES).'.';

        return implode("\n\n", $text);
    }

    /**
     * @return array{type: string, dataset: string, geo: string, time: string}
     */
    private static function example(CatalogEntry $entry): array
    {
        // A country rather than an aggregate such as EU27_2020: the page is about destinations.
        $countries = array_values(array_filter($entry->geo, static fn (string $geo): bool => 1 === preg_match('/^[A-Z]{2}$/D', $geo)));

        return [
            'type' => 'measured',
            'dataset' => $entry->id,
            // An added dataset may hold every country of the world: France rather than the first by its code.
            // (The shipped entries keep their first country: the recorded answers hold this text.)
            'geo' => str_starts_with($entry->id, 'x_') && \in_array('FR', $countries, true) ? 'FR' : ($countries[0] ?? $entry->geo[0]),
            'time' => $entry->time->to,
        ];
    }

    /** The places of an entry, with the names of its cities: the model knows "Seville", not ES004C. */
    private static function places(CatalogEntry $entry): string
    {
        if ([] === $entry->names) {
            return 'Places (Eurostat codes, e.g. EL for Greece): '.implode(', ', $entry->geo).'.';
        }

        return 'Places (Eurostat codes; a city code such as ES004C starts with the code of its country): '
            .implode(', ', array_map(static fn (string $geo): string => isset($entry->names[$geo]) ? \sprintf('%s (%s)', $geo, $entry->names[$geo]) : $geo, $entry->geo)).'.';
    }

    /** The periods of an entry, and how to write them. */
    private static function periods(CatalogEntry $entry): string
    {
        $time = $entry->time;

        return match ($time->granularity) {
            Granularity::Annual => \sprintf('years %s to %s, written like "%s"', $time->from, $time->to, $time->to),
            Granularity::Monthly => \sprintf('months %s to %s, written like "%s"', $time->from, $time->to, $time->to),
            Granularity::MonthOfYear => \sprintf('months of the year, written M01 to M12, averaged over %s', $time->normal),
        };
    }
}
