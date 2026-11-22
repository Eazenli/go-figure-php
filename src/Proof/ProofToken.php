<?php

declare(strict_types=1);

namespace App\Proof;

/**
 * The stateless proof link: /proof/{base64url(canonical JSON)}.
 *
 * The token carries the whole proof, so the web page needs no state shared with the MCP server. It
 * is only trusted after the cell has been resolved again and the fingerprints compared.
 */
final class ProofToken
{
    /** Far above any real proof; keeps a forged link from making the server parse megabytes. */
    private const int MAX_LENGTH = 4096;

    /**
     * @param array<string, mixed> $proof
     */
    public static function encode(array $proof): string
    {
        return rtrim(strtr(base64_encode(Canonical::encode($proof)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>|null null when the token is not a canonical proof
     */
    public static function decode(string $token): ?array
    {
        if (\strlen($token) > self::MAX_LENGTH || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $token)) {
            return null;
        }

        $json = base64_decode(strtr($token, '-_', '+/'), true);
        if (false === $json) {
            return null;
        }

        try {
            $proof = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        // Only one spelling of a proof is valid: re-encoding must give the same token.
        if (!\is_array($proof) || array_is_list($proof) || self::encode($proof) !== $token) {
            return null;
        }

        return $proof;
    }
}
