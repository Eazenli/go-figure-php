<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Catalog\Catalog;
use App\Catalog\CatalogLoader;
use Symfony\Component\Validator\Validation;

/**
 * The catalog of the tests (tests/fixtures/catalog), outside the container.
 */
final class CatalogFixture
{
    public const string DIR = __DIR__.'/../fixtures/catalog';

    public static function loader(): CatalogLoader
    {
        return new CatalogLoader(Validation::createValidator());
    }

    /**
     * @param string $cacheDir a fresh directory: the catalog writes its cache there
     */
    public static function catalog(string $cacheDir, string $dir = self::DIR): Catalog
    {
        return new Catalog(self::loader(), $dir, $cacheDir, true);
    }
}
