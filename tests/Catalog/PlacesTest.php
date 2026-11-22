<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Places;
use App\Tests\Support\CatalogFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Filesystem\Filesystem;

final class PlacesTest extends TestCase
{
    private string $dir;
    private Places $places;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/go-figure-places-'.bin2hex(random_bytes(4));
        $this->places = new Places(CatalogFixture::catalog($this->dir), new IdentityTranslator());
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function testCountriesAreNamedByIcuAndCitiesByTheCatalog(): void
    {
        self::assertSame('Greece', $this->places->name('EL'));
        self::assertSame('Spain', $this->places->name('ES'));
        self::assertSame('European Union (without the United Kingdom)', $this->places->name('EU27_2020'));
        self::assertSame('Seville', $this->places->name('ES004C'));
        // A code nobody names stays a code: the page never makes up a name.
        self::assertSame('ES999C', $this->places->name('ES999C'));
        self::assertSame('XYZ', $this->places->name('XYZ'));
    }

    public function testTheScaleSaysWhatAFigureCovers(): void
    {
        self::assertSame('the whole country', $this->places->scale('ES'));
        self::assertSame('a city in Spain', $this->places->scale('ES004C'));
        self::assertSame('a city in Greece', $this->places->scale('EL001C'));
        self::assertSame('a city in the Netherlands', $this->places->scale('NL001C'));
        self::assertSame('a group of countries', $this->places->scale('EU27_2020'));
        self::assertSame('', $this->places->scale('XYZ'));
    }

    public function testACityBelongsToTheCountryItsCodeStartsWith(): void
    {
        self::assertSame('ES', Places::country('ES004C'));
        self::assertSame('EL', Places::country('EL001C'));
        self::assertSame('FR', Places::country('FR'));
        self::assertNull(Places::country('EU27_2020'));
        self::assertFalse(Places::isCity('ES004'));
        self::assertFalse(Places::isCity('ES0004C'));
        // The same number ending in K or F is the greater city or the commuting zone.
        self::assertFalse(Places::isCity('ES004K'));
        self::assertFalse(Places::isCity('ES004F'));
    }

    public function testChoicesAreGroupedByKindAndSortedByName(): void
    {
        self::assertSame(
            ['Countries' => ['FR', 'EL', 'ES'], 'Cities' => ['PT001C', 'ES004C'], 'Groups of countries' => ['EU27_2020'], 'Other places' => ['ES004K']],
            $this->places->choices(['ES', 'ES004C', 'EU27_2020', 'FR', 'PT001C', 'EL', 'ES004K', 'ES']),
        );
        // A single kind needs no group.
        self::assertSame(['FR', 'ES'], $this->places->choices(['ES', 'FR']));
    }

    public function testAChoiceShowsTheNameAndTheCountryOfACityNeverTheCode(): void
    {
        self::assertSame('Seville, Spain', $this->places->label('ES004C'));
        self::assertSame('Greece', $this->places->label('EL'));
        self::assertSame('ES999C', $this->places->label('ES999C'));
    }
}
