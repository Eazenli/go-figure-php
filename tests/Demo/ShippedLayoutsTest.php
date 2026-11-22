<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use App\Layout\Page;
use App\Tests\Support\FactsFixture;
use App\Ui\PageRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Every layout shipped in demo/, in English and in French, hides exactly what it means to: nothing
 * in the answers to the example questions, the model's number on the home page, the title and the
 * note of the "digits" attack.
 */
final class ShippedLayoutsTest extends KernelTestCase
{
    private const string DEMO = __DIR__.'/../../demo';

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function layouts(): iterable
    {
        foreach (glob(self::DEMO.'/scripts/*.json') ?: [] as $file) {
            yield 'scripts/'.basename($file) => [$file, 0];
        }
        foreach (['home.json', 'home.fr.json'] as $name) {
            yield $name => [self::DEMO.'/'.$name, 1];
        }
        // The "digits" attack hides its title and its note, "source" its note (a web address); "swap"
        // hides nothing: a flattering cell is proven, and its caption says which one (a limit, said on /lie).
        foreach (['start' => 0, 'cell' => 0, 'digits' => 2, 'source' => 1, 'swap' => 0, 'compute' => 0] as $attack => $hidden) {
            foreach (['', '.fr'] as $language) {
                yield 'lie/'.$attack.$language.'.json' => [self::DEMO.'/lie/'.$attack.$language.'.json', $hidden];
            }
        }
    }

    #[DataProvider('layouts')]
    public function testEachLayoutHidesExactlyWhatItMeansTo(string $file, int $hidden): void
    {
        $container = self::getContainer();
        FactsFixture::write($container->getParameter('app.facts_path'));
        $page = $container->get(SerializerInterface::class)->deserialize((string) file_get_contents($file), Page::class, 'json');

        self::assertSame($hidden, $container->get(PageRenderer::class)->view($page)->tally()['hidden']);
    }
}
