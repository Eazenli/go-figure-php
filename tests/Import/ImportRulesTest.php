<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\EurostatUnavailable;
use App\Import\Importer;
use App\Import\JsonStat;
use App\Import\UnitLabel;
use PHPUnit\Framework\TestCase;

/**
 * The rules of an import that need no server: JSON-stat decoded as data/refresh.py does, the scale
 * of a unit written by the server, and the id of an imported entry.
 */
final class ImportRulesTest extends TestCase
{
    public function testACellIsDecodedWithItsFlagsWhateverTheShapeOfTheAnswer(): void
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../fixtures/eurostat/tour_occ_arnat_data.json'), true, flags: \JSON_THROW_ON_ERROR);
        $cells = JsonStat::cells($data);
        $data['dimension']['geo']['category']['index'] = ['ES', 'FR', 'UK'];
        $data['value'] = [20000, null, 58000000, 58740022, 9, 9];
        $data['status'] = [null, '|C', null, 'p', null, null];

        foreach ([$cells, JsonStat::cells($data)] as $decoded) {
            self::assertContains(['ES', '2024', 20000.0, ''], $decoded);
            self::assertContains(['ES', '2025', null, 'c'], $decoded, 'Confidential: a row with no value.');
            self::assertContains(['FR', '2025', 58740022.0, 'p'], $decoded);
            self::assertCount(6, $decoded);
        }
    }

    public function testADimensionLeftLooseIsAnError(): void
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../fixtures/eurostat/tour_occ_arnat_structure.json'), true, flags: \JSON_THROW_ON_ERROR);

        $this->expectException(EurostatUnavailable::class);
        JsonStat::cells($data);
    }

    public function testTheScaleOfAUnitIsWrittenByTheServer(): void
    {
        self::assertSame('thousand passengers', UnitLabel::of('THS_PAS', 'passengers'));
        self::assertSame('million passenger-kilometres', UnitLabel::of('MIO_PKM', 'passenger-kilometres'));
        self::assertSame('nights per thousand inhabitants', UnitLabel::of('P_THAB', 'nights'));
        self::assertSame('thousand passengers', UnitLabel::of('THS_PAS', 'thousand passengers'), 'Not twice.');
        self::assertSame('arrivals', UnitLabel::of('NR', 'arrivals'));
    }

    public function testTheSameChoiceGetsTheSameIdAndAnotherChoiceAnother(): void
    {
        $id = Importer::id('tour_occ_arnat', ['unit' => 'NR', 'c_resid' => 'FOR']);

        self::assertMatchesRegularExpression('/^x_tour_occ_arnat_[0-9a-f]{6}$/', $id);
        self::assertSame($id, Importer::id('tour_occ_arnat', ['c_resid' => 'FOR', 'unit' => 'NR']));
        self::assertNotSame($id, Importer::id('tour_occ_arnat', ['unit' => 'NR', 'c_resid' => 'DOM']));
        self::assertLessThanOrEqual(40, \strlen(Importer::id(str_repeat('a', 40), [])));
    }
}
