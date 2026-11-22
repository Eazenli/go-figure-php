<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\Importer;
use App\Import\WorldBankApi;
use App\Import\WorldBankImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The World Bank as a second source of the extended mode: which indicators may be added (rule 5),
 * and how the server writes a title and a unit from the World Bank's own name of an indicator.
 */
final class WorldBankTest extends TestCase
{
    public function testAnIndicatorIsTakenWhenEveryTopicItIsFiledUnderIsAccepted(): void
    {
        self::assertTrue(WorldBankApi::allowed('AG.LND.FRST.ZS', 'Forest area (% of land area)', ['1', '6', '19']));
        self::assertTrue(WorldBankApi::allowed('NY.GDP.MKTP.CD', 'GDP (current US$)', ['3']));
        // Health is not a topic Go Figure takes, even next to one it does.
        self::assertFalse(WorldBankApi::allowed('SH.DYN.MORT', 'Mortality rate, under-5 (per 1,000 live births)', ['8']));
        self::assertFalse(WorldBankApi::allowed('SH.XPD.CHEX.GD.ZS', 'Current health expenditure (% of GDP)', ['3', '8']));
        self::assertTrue(WorldBankApi::allowed('TG.VAL.TOTL.GD.ZS', 'Merchandise trade (% of GDP)', ['21']));
        // The Millennium development goals are mostly about people: not a topic Go Figure takes.
        self::assertFalse(WorldBankApi::allowed('XX.MDG', 'Some goal', ['18']));
        // No topic at all: not taken on trust.
        self::assertFalse(WorldBankApi::allowed('XX.YY.ZZ', 'Something', []));
    }

    public function testTheSecondNetOnTheNameAndThePopulationTakenByItsId(): void
    {
        self::assertFalse(WorldBankApi::allowed('MS.MIL.XPND.GD.ZS', 'Military expenditure (% of GDP)', ['3']));
        self::assertFalse(WorldBankApi::allowed('SL.UEM.TOTL.ZS', 'Unemployment, total (% of total labor force)', ['3']));
        self::assertTrue(WorldBankApi::allowed('SP.POP.TOTL', 'Population, total', ['8', '19']));
    }

    /**
     * @param array{title: string, code: string, label: string} $expected
     */
    #[DataProvider('names')]
    public function testTheServerWritesTheTitleAndTheUnitFromTheNameOfTheIndicator(string $name, array $expected): void
    {
        self::assertSame($expected, WorldBankImporter::words($name));
    }

    /** @return iterable<string, array{string, array{title: string, code: string, label: string}}> */
    public static function names(): iterable
    {
        yield 'what it counts, in its own words' => ['International tourism, number of arrivals', ['title' => 'International tourism', 'code' => Importer::NO_UNIT, 'label' => 'arrivals']];
        yield 'a share: the number carries the sign' => ['Forest area (% of land area)', ['title' => 'Forest area', 'code' => 'PC', 'label' => 'of land area']];
        yield 'a currency, written in words' => ['GDP (current US$)', ['title' => 'GDP', 'code' => Importer::NO_UNIT, 'label' => 'current US dollars']];
        yield 'a unit as the World Bank writes it' => ['Electric power consumption (kWh per capita)', ['title' => 'Electric power consumption', 'code' => Importer::NO_UNIT, 'label' => 'kWh per capita']];
        yield 'no unit in the name: none is made up' => ['Population, total', ['title' => 'Population, total', 'code' => Importer::NO_UNIT, 'label' => '']];
        yield 'a currency of comparison' => ['GDP, PPP (current international $)', ['title' => 'GDP, PPP', 'code' => Importer::NO_UNIT, 'label' => 'current international dollars']];
        yield 'a bare percentage' => ['Real interest rate (%)', ['title' => 'Real interest rate', 'code' => 'PC', 'label' => '%']];
    }

    public function testTheIdOfAnImportedIndicatorSaysItsSource(): void
    {
        self::assertSame('x_wb_ny_gdp_mktp_cd', WorldBankImporter::id('NY.GDP.MKTP.CD'));
        self::assertLessThanOrEqual(40, \strlen(WorldBankImporter::id('AG.LND.TOTL.RU.K2.SOMETHING.VERY.LONG.INDEED')));
    }
}
