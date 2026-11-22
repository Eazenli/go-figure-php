<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\EurostatApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which Eurostat datasets a visitor may add: the families about what countries produce, trade,
 * move and measure, and never a subject about people's lives (rule 5 of the project).
 */
final class EurostatFamiliesTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testADatasetOfAnAcceptedFamilyMayBeAdded(string $code, string $title): void
    {
        self::assertTrue(EurostatApi::allowed($code));
        self::assertTrue(EurostatApi::allowed($code, $title));
    }

    /** @return iterable<string, array{string, string}> */
    public static function accepted(): iterable
    {
        yield 'tourism' => ['tour_occ_ninat', 'Nights spent at tourist accommodation establishments'];
        yield 'the economy' => ['nama_10_gdp', 'Gross domestic product (GDP) and main components (output, expenditure and income)'];
        yield 'energy' => ['nrg_ind_ren', 'Share of energy from renewable sources'];
        yield 'agriculture' => ['apro_mk_farm', "Production of cow's milk on farms"];
        yield 'research' => ['rd_e_gerdtot', 'GERD by sector of performance'];
        yield 'the digital economy' => ['isoc_ci_in_h', 'Households with access to the internet at home'];
        yield 'the population, taken by its code' => ['demo_pjan', 'Population on 1 January by age and sex'];
    }

    public function testTheSameNetReadsTheLabelOfACodeAndLetsAPriceThrough(): void
    {
        // The label of a code chosen in an accepted dataset.
        self::assertTrue(EurostatApi::sensitive('Deaths - total'));
        self::assertTrue(EurostatApi::sensitive('Foreign-born persons'));
        self::assertTrue(EurostatApi::sensitive('Alcoholic beverages, tobacco and narcotics'));
        // A price is not a subject about people's lives.
        self::assertFalse(EurostatApi::sensitive('Alcoholic beverages'));
        self::assertFalse(EurostatApi::sensitive('Price level indices (EU27_2020=100)'));
    }

    #[DataProvider('refusedByFamily')]
    public function testAFamilyAboutPeoplesLivesIsRefusedByItsCodeAlone(string $code): void
    {
        self::assertFalse(EurostatApi::allowed($code));
    }

    /** @return iterable<string, array{string}> */
    public static function refusedByFamily(): iterable
    {
        yield 'health' => ['hlth_cd_asdr2'];
        yield 'migration and asylum' => ['migr_asyappctza'];
        yield 'crime' => ['crim_gen_reg'];
        yield 'living conditions' => ['ilc_peps01n'];
        yield 'the labour force' => ['lfsa_urgan'];
        yield 'the rest of demography' => ['demo_minfind'];
        // Its indicators include deaths, births and net migration: no longer taken by its code.
        yield 'the demographic balance' => ['demo_gind'];
        yield 'accident deaths, within transport' => ['tran_sf_roadus'];
    }

    #[DataProvider('refusedByTitle')]
    public function testADatasetOfAnAcceptedFamilyIsRefusedWhenItsTitleIsAboutPeople(string $code, string $title): void
    {
        self::assertTrue(EurostatApi::allowed($code), 'its family is accepted');
        self::assertFalse(EurostatApi::allowed($code, $title));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedByTitle(): iterable
    {
        yield 'victims' => ['tran_r_acci', 'Victims in road accidents by NUTS 2 region'];
        yield 'citizenship' => ['rd_p_perscitz', 'Researchers by sector of performance, country of citizenship and sex'];
        yield 'unemployment' => ['ei_lm_m_vtg', 'Unemployment - monthly data - vintages from 2021 onwards'];
        yield 'work' => ['sbs_na_sca_r2', 'Persons employed in the business economy'];
        yield 'corruption' => ['bs_bs12_04', 'Enterprises by perceived level of bribery'];
        yield 'railway accidents' => ['rail_ac_catnmbr', 'Railway accidents by type of accident - annual data (2004-2015)'];
    }

    /**
     * A dataset without a unit code: a code whose label carries a scale cannot be chosen, whether
     * the scale is said in words or written as a number before the unit. Found on a real import:
     * "Total production of grape must or wine (1 000 hl)" was shown as plain hectolitres.
     */
    public function testAScaleWrittenAsANumberIsSeenLikeOneInWords(): void
    {
        $scaled = (string) new \ReflectionClassConstant(\App\Import\Importer::class, 'SCALED')->getValue();

        foreach (['Total production of grape must or wine (1 000 hl)', 'Production (1000 t)', 'Harvested production - thousand tonnes', "Output ('000 tonnes)", 'Million euro', 'Nights per inhabitant'] as $label) {
            self::assertSame(1, preg_match($scaled, $label), $label);
        }
        foreach (['Area (ha)', 'Nights spent', 'Hotels and similar accommodation', 'Total'] as $label) {
            self::assertSame(0, preg_match($scaled, $label), $label);
        }
    }
}
