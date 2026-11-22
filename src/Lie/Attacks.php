<?php

declare(strict_types=1);

namespace App\Lie;

use App\Catalog\Catalog;
use App\Data\Facts;

/**
 * The prepared attacks of "Attack this answer", on any layout: each one changes what a model sent
 * the way a careless or a dishonest model would, and says what it changed. The server then checks
 * the changed layout like any other: nothing here decides what is shown.
 */
final readonly class Attacks
{
    /** The attacks, in the order of their buttons, and the words of each button. */
    public const array LABELS = [
        'digits' => 'Slip a number into the text',
        'value' => 'Write the value yourself',
        'cell' => 'Ask for a cell the data does not have',
        'compute' => 'Ask for a calculation that means nothing',
        'source' => 'Invent a source',
        'swap' => 'Pick a flattering cell',
    ];

    /** What each attack changed, said above the result. */
    public const array SAID = [
        'digits' => 'A sentence of the model now says "up 12% on the year before": a number it wrote itself.',
        'value' => 'The first figure now carries a value of its own, next to the cell it names.',
        'cell' => 'The first figure now asks for a place the data does not have.',
        'compute' => 'A new tile asks for the growth from one place to another, as if they were two dates.',
        'source' => 'A sentence of the model now cites a ministry and a web address of its own.',
        'swap' => 'The first figure now names another period, under the same words. The server proves it: the check does not stop this.',
    ];

    /** A place code no source uses. */
    private const string NOWHERE = 'ZZ';

    public function __construct(
        private Catalog $catalog,
        private Facts $facts,
    ) {
    }

    /**
     * The attacks this layout can take: the ones on a figure need a figure.
     *
     * @param array<mixed> $layout
     *
     * @return list<string>
     */
    public function available(array $layout): array
    {
        return array_values(array_filter(array_keys(self::LABELS), fn (string $kind): bool => null !== $this->apply($kind, $layout)));
    }

    /**
     * @param array<mixed> $layout
     *
     * @return array<mixed>|null the layout once attacked; null when it has nothing this attack changes
     */
    public function apply(string $kind, array $layout): ?array
    {
        if (!\is_array($layout['components'] ?? null) || !array_is_list($layout['components'])) {
            return null;
        }
        if ('digits' === $kind) {
            return self::withSentence($layout, ' That is up 12% on the year before.');
        }
        if ('source' === $kind) {
            return self::withSentence($layout, ' Source: the Ministry of Tourism, www.tourism.example.');
        }
        $path = self::firstCell($layout['components']);
        if (null === $path) {
            return null;
        }
        $cell = self::at($layout['components'], $path);
        $dataset = \is_string($cell['dataset'] ?? null) ? $cell['dataset'] : '';
        $geo = \is_string($cell['geo'] ?? null) ? $cell['geo'] : '';
        // A series names its last period as "to".
        $time = \is_string($cell['time'] ?? null) ? $cell['time'] : (\is_string($cell['to'] ?? null) ? $cell['to'] : '');
        $entry = $this->catalog->get($dataset);

        switch ($kind) {
            case 'value':
                $cell['value'] = 999999;
                break;
            case 'cell':
                $cell['geo'] = self::NOWHERE;
                break;
            case 'swap':
                // Another period of the same dataset: the earliest, or the next when it is the one named.
                // Another period of the same dataset that has a value for this place: the earliest.
                $periods = $entry?->time->periods() ?? [];
                $other = array_values(array_filter($periods, fn (string $period): bool => $period !== $time && null !== $this->facts->find($dataset, $geo, $period)?->value))[0] ?? null;
                if (null === $other || !isset($cell['time'])) {
                    return null;
                }
                $cell['time'] = $other;
                break;
            case 'compute':
                $elsewhere = array_values(array_filter($entry->geo ?? [], static fn (string $place): bool => $place !== $geo))[0] ?? null;
                if (null === $elsewhere || '' === $time) {
                    return null;
                }
                $layout['components'][] = ['kind' => 'kpi', 'label' => 'Growth', 'figure' => ['type' => 'computed', 'op' => 'change_pct', 'operands' => [
                    ['type' => 'measured', 'dataset' => $dataset, 'geo' => $geo, 'time' => $time],
                    ['type' => 'measured', 'dataset' => $dataset, 'geo' => $elsewhere, 'time' => $time],
                ]]];

                return $layout;
            default:
                return null;
        }
        $layout['components'] = self::put($layout['components'], $path, $cell);

        return $layout;
    }

    /**
     * The layout with one more sentence of the model, in a note of its own: the notes it already
     * had stay as they were, so the page shows what the attack added and nothing else.
     *
     * @param array<mixed> $layout
     *
     * @return array<mixed>
     */
    private static function withSentence(array $layout, string $sentence): array
    {
        $layout['components'][] = ['kind' => 'note', 'segments' => [['kind' => 'text', 'text' => ltrim($sentence)]]];

        return $layout;
    }

    /**
     * Where the first cell of a page is: a measured figure (a tile, a bar, a row, a figure in a
     * note, the first cell of a calculation) or a series of a line chart.
     *
     * @param array<mixed> $node
     *
     * @return list<int|string>|null
     */
    private static function firstCell(array $node): ?array
    {
        if (\is_string($node['dataset'] ?? null) && \is_string($node['geo'] ?? null)) {
            return [];
        }
        foreach ($node as $key => $child) {
            if (\is_array($child)) {
                $path = self::firstCell($child);
                if (null !== $path) {
                    return [$key, ...$path];
                }
            }
        }

        return null;
    }

    /**
     * @param array<mixed>      $node
     * @param list<int|string> $path
     *
     * @return array<mixed>
     */
    private static function at(array $node, array $path): array
    {
        foreach ($path as $key) {
            $node = $node[$key];
            \assert(\is_array($node));
        }

        return $node;
    }

    /**
     * @param array<mixed>      $node
     * @param list<int|string> $path
     * @param array<mixed>      $value
     *
     * @return array<mixed>
     */
    private static function put(array $node, array $path, array $value): array
    {
        if ([] === $path) {
            return $value;
        }
        $key = array_shift($path);
        \assert(\is_array($node[$key]));
        $node[$key] = self::put($node[$key], $path, $value);

        return $node;
    }
}
