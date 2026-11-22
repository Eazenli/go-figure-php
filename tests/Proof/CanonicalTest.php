<?php

declare(strict_types=1);

namespace App\Tests\Proof;

use App\Proof\Canonical;
use PHPUnit\Framework\TestCase;

final class CanonicalTest extends TestCase
{
    public function testTheExampleOfContractFiveHasOneExactForm(): void
    {
        $proof = [
            'v' => 1, 'type' => 'measured', 'provider' => 'eurostat', 'dataset' => 'nights_total', 'geo' => 'FR',
            'time' => '2024', 'unit' => 'NR', 'value' => 457644262.0, 'status' => '', 'source_updated' => '2026-10-12',
            'snapshot_date' => '2026-11-12', 'url' => 'https://ec.europa.eu/eurostat/databrowser/view/tour_occ_ninat/default/table',
        ];
        $expected = '{"dataset":"nights_total","geo":"FR","provider":"eurostat","snapshot_date":"2026-11-12","source_updated":"2026-10-12","status":"","time":"2024","type":"measured","unit":"NR","url":"https://ec.europa.eu/eurostat/databrowser/view/tour_occ_ninat/default/table","v":1,"value":457644262}';

        self::assertSame($expected, Canonical::encode($proof));
        self::assertSame(hash('sha256', $expected), Canonical::fingerprint($proof));
    }

    public function testAFloatFromSqliteAndAnIntegerFromALinkGiveTheSameForm(): void
    {
        self::assertSame(Canonical::encode(['value' => 457644262]), Canonical::encode(['value' => 457644262.0]));
        self::assertSame('{"value":14.411}', Canonical::encode(['value' => 14.411]));
    }

    public function testNestedKeysAreSortedAndListsKeepTheirOrder(): void
    {
        self::assertSame(
            '{"a":1,"operands":[{"x":1,"y":2},{"x":3,"y":4}],"z":"é/"}',
            Canonical::encode(['z' => 'é/', 'operands' => [['y' => 2, 'x' => 1], ['y' => 4, 'x' => 3]], 'a' => 1]),
        );
    }

    public function testNotANumberIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Canonical::encode(['value' => \NAN]);
    }
}
