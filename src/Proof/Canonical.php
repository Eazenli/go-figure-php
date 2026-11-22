<?php

declare(strict_types=1);

namespace App\Proof;

/**
 * The canonical form of a proof: JSON with sorted keys and no whitespace, in the
 * manner of RFC 8785. Its SHA-256 is the proof's fingerprint.
 *
 * Integral numbers print without a fraction (457644262, not 457644262.0), so a value read as a
 * float from SQLite and the same value decoded as an integer from a link give the same form.
 */
final class Canonical
{
    /**
     * @param array<string, mixed> $data
     */
    public static function encode(array $data): string
    {
        return json_encode(self::sort($data), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fingerprint(array $data): string
    {
        return hash('sha256', self::encode($data));
    }

    private static function sort(mixed $value): mixed
    {
        if (\is_float($value) && !is_finite($value)) {
            throw new \InvalidArgumentException('A proof cannot hold NaN or an infinite number.');
        }
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return array_map(self::sort(...), $value);
    }
}
