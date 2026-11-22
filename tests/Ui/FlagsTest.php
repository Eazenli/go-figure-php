<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Ui\Twig\FigureExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A flag of Eurostat is a letter, and a cell may carry several ("be"): each one is explained, in
 * English and in French, and a letter we do not know still says it is a flag of the source.
 */
final class FlagsTest extends TestCase
{
    public function testEachLetterOfAFlagIsExplained(): void
    {
        self::assertSame(['break in series', 'estimated'], FigureExtension::flagWords('be'));
        self::assertSame(['confidential'], FigureExtension::flagWords('c'));
        self::assertSame(['estimated', 'source flag'], FigureExtension::flagWords('ex'));
        self::assertSame([], FigureExtension::flagWords(''));
    }

    public function testEveryFlagIsTranslated(): void
    {
        $french = Yaml::parseFile(__DIR__.'/../../translations/messages.fr.yaml');
        self::assertIsArray($french);

        foreach ([...FigureExtension::FLAGS, 'source flag'] as $words) {
            self::assertArrayHasKey($words, $french, $words);
        }
    }
}
