<?php

declare(strict_types=1);

namespace App\Tests\Proof;

use App\Proof\ProofToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProofTokenTest extends TestCase
{
    public function testATokenDecodesToTheProofItCarries(): void
    {
        $proof = ['v' => 1, 'type' => 'measured', 'dataset' => 'nights_total', 'url' => 'https://example.org/a?b=c', 'value' => 14.4];
        $token = ProofToken::encode($proof);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
        self::assertEquals($proof, ProofToken::decode($token));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTokens(): iterable
    {
        $b64 = static fn (string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        yield 'not base64url' => ['abc$def'];
        yield 'padded' => [base64_encode('{"a":1}')];
        yield 'not JSON' => [$b64('not json')];
        yield 'a list' => [$b64('[1,2]')];
        yield 'a scalar' => [$b64('42')];
        yield 'unsorted keys' => [$b64('{"b":1,"a":2}')];
        yield 'with spaces' => [$b64('{"a": 1}')];
        yield 'too long' => [$b64('{"a":"'.str_repeat('x', 4000).'"}')];
    }

    #[DataProvider('invalidTokens')]
    public function testOnlyTheCanonicalSpellingOfAProofIsAccepted(string $token): void
    {
        self::assertNull(ProofToken::decode($token));
    }
}
