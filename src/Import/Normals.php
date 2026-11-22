<?php

declare(strict_types=1);

namespace App\Import;

/**
 * The monthly climate normals of a city, computed from the daily values of Open-Meteo exactly as
 * data/weather.py does for the shipped cities (its tests are the reference): the mean of the daily
 * maxima of each month; the mean of the monthly totals of precipitation, a month of a year with a day
 * missing left out. A month with more than a tenth of its days (of its years, for precipitation)
 * missing gets no normal. Computed in tenths, as whole numbers, and rounded half up to one decimal.
 */
final class Normals
{
    public const string START = '1991-01-01';
    public const string END = '2020-12-31';
    public const int DAYS = 10958;
    /** The daily variable of each dataset, and the unit the answer must give. */
    public const array VARIABLES = ['temperature_2m_max' => '°C', 'precipitation_sum' => 'mm'];

    /**
     * @param array<string, mixed> $response the answer of the archive API
     *
     * @throws \UnexpectedValueException when the answer is not what the normals are computed from
     */
    public static function check(array $response): void
    {
        $days = $response['daily']['time'] ?? null;
        if (!\is_array($days) || self::DAYS !== \count($days) || self::START !== $days[0] || self::END !== $days[self::DAYS - 1]) {
            throw new \UnexpectedValueException('Not the daily values of the reference period.');
        }
        foreach (self::VARIABLES as $variable => $unit) {
            if ($unit !== ($response['daily_units'][$variable] ?? null) || !\is_array($response['daily'][$variable] ?? null) || self::DAYS !== \count($response['daily'][$variable])) {
                throw new \UnexpectedValueException(\sprintf('Unexpected unit or values for %s.', $variable));
            }
            foreach ($response['daily'][$variable] as $value) {
                // A missing day, or a plausible value: nothing else is averaged.
                if (null !== $value && (!\is_int($value) && !\is_float($value) || !is_finite((float) $value) || $value < -90 || $value > 2000)) {
                    throw new \UnexpectedValueException(\sprintf('Unexpected value for %s.', $variable));
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $response checked
     *
     * @return array<string, float> "M01" to "M12" => the normal, one decimal
     */
    public static function of(array $response, string $variable): array
    {
        $days = $response['daily']['time'];
        $values = $response['daily'][$variable];
        /** @var array<string, list<int|null>> $series month => tenths, or null where missing */
        $series = [];
        if ('precipitation_sum' === $variable) {
            $totals = [];
            foreach ($days as $i => $day) {
                $key = substr((string) $day, 0, 7);
                $value = $values[$i] ?? null;
                if (!\array_key_exists($key, $totals)) {
                    $totals[$key] = 0;
                }
                $totals[$key] = null === $totals[$key] || !is_numeric($value) ? null : $totals[$key] + self::tenths($value);
            }
            foreach ($totals as $key => $total) {
                $series[substr($key, 5, 2)][] = $total;
            }
        } else {
            foreach ($days as $i => $day) {
                $value = $values[$i] ?? null;
                $series[substr((string) $day, 5, 2)][] = is_numeric($value) ? self::tenths($value) : null;
            }
        }

        $normals = [];
        ksort($series);
        foreach ($series as $month => $monthValues) {
            $known = array_values(array_filter($monthValues, static fn (?int $v): bool => null !== $v));
            if ([] === $known || (\count($monthValues) - \count($known)) * 10 > \count($monthValues)) {
                continue;
            }
            $normals['M'.$month] = self::mean(array_sum($known), \count($known));
        }

        return $normals;
    }

    private static function tenths(mixed $value): int
    {
        return (int) round((float) $value * 10);
    }

    /** The mean of values in tenths, rounded half up (away from zero) to one decimal. */
    private static function mean(int $sum, int $count): float
    {
        $rounded = intdiv(2 * abs($sum) + $count, 2 * $count);

        return ($sum < 0 ? -$rounded : $rounded) / 10;
    }
}
