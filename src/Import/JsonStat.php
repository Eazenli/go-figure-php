<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Reads a JSON-stat answer of the Statistics API 1.0, as data/refresh.py does: the
 * index and the flags come as an object or as a list, a cell may have a flag and no value, and every
 * dimension but geo and time must hold one category.
 */
final class JsonStat
{
    /**
     * The codes of a category index, in order, whether it is a list or an object.
     *
     * @return list<string>
     */
    public static function codes(mixed $index): array
    {
        if (!\is_array($index)) {
            return [];
        }
        if (array_is_list($index)) {
            return array_map(strval(...), $index);
        }
        asort($index);

        return array_map(strval(...), array_keys($index));
    }

    /**
     * Every cell of the answer: [geo, time, value or null, flag].
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{string, string, ?float, string}>
     *
     * @throws EurostatUnavailable when the answer is not one dataset by place and period
     */
    public static function cells(array $data): array
    {
        $ids = $data['id'] ?? null;
        $sizes = $data['size'] ?? null;
        if (!\is_array($ids) || !\is_array($sizes) || \count($ids) !== \count($sizes)) {
            throw new EurostatUnavailable('The answer of Eurostat is not JSON-stat.');
        }
        $ids = array_values($ids);
        $sizes = array_values(array_map(intval(...), $sizes));
        $codes = [];
        foreach ($ids as $i => $id) {
            $codes[$id] = self::codes($data['dimension'][$id]['category']['index'] ?? []);
            if (!\in_array($id, ['geo', 'time'], true) && 1 !== $sizes[$i]) {
                throw new EurostatUnavailable(\sprintf('The dimension %s is not frozen.', $id));
            }
        }
        $geo = array_search('geo', $ids, true);
        $time = array_search('time', $ids, true);
        if (false === $geo || false === $time) {
            throw new EurostatUnavailable('The answer of Eurostat has no place or no period.');
        }

        $strides = array_fill(0, \count($sizes), 1);
        for ($i = \count($sizes) - 2; $i >= 0; --$i) {
            $strides[$i] = $strides[$i + 1] * $sizes[$i + 1];
        }
        $values = self::map($data['value'] ?? []);
        $flags = self::map($data['status'] ?? []);

        $cells = [];
        foreach (array_keys($values + $flags) as $position) {
            $n = (int) $position;
            $value = $values[$position] ?? null;
            // Before "|", the flags of the value (":" is "not available"); after it, confidentiality.
            [$observation, $confidentiality] = array_pad(explode('|', (string) ($flags[$position] ?? ''), 2), 2, '');
            $flag = strtolower((string) preg_replace('/[^A-Za-z]/', '', $observation)).('' !== trim($confidentiality) ? 'c' : '');
            $cells[] = [
                $codes['geo'][intdiv($n, $strides[$geo]) % $sizes[$geo]] ?? '',
                $codes['time'][intdiv($n, $strides[$time]) % $sizes[$time]] ?? '',
                is_numeric($value) ? (float) $value : null,
                $flag,
            ];
        }

        return $cells;
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $items): array
    {
        if (!\is_array($items)) {
            return [];
        }
        $map = [];
        foreach ($items as $key => $item) {
            if (null !== $item) {
                $map[(string) $key] = $item;
            }
        }

        return $map;
    }
}
