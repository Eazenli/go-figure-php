<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AskPrompt;
use App\Ai\Cassettes;
use App\Ai\Platforms;
use App\Ai\Question;
use App\Ai\Questions;
use App\Catalog\Catalog;
use App\Layout\Page;
use App\Tests\Support\CatalogFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The example questions shipped in demo/: each cassette still matches the request /ask sends
 * today (prompt, schema, shipped catalog). A change to any of them fails here, not in front of a
 * visitor: re-record with app:cassette.
 */
final class DemoCassettesTest extends KernelTestCase
{
    public function testEveryExampleQuestionReplaysAgainstThePromptSchemaAndCatalogOfToday(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $root = (string) $container->getParameter('kernel.project_dir');
        $catalog = $this->catalog($root);
        $cassettes = new Cassettes(new Platforms($container->get('event_dispatcher'), new MockHttpClient()), new AskPrompt($catalog), new MockHttpClient());
        $questions = new Questions($root.'/demo/questions.yaml', $root);
        self::assertNotEmpty($questions->all());

        foreach ($questions->all() as $question) {
            $result = $cassettes->replay($question);
            foreach ($result->asStream() as $ignored) {
                // read the whole answer
            }
            $page = $result->asObject(Page::class);

            if (Question::SCRIPTED === $question->recorded) {
                self::assertNotNull($question->script, $question->id);
                $script = json_decode((string) file_get_contents($question->script), true, flags: \JSON_THROW_ON_ERROR);
                self::assertSame($script['title'], $page->title, $question->id.': the cassette holds its script');
            }
        }
    }

    public function testEveryScriptNamesCellsOfTheShippedCatalog(): void
    {
        $root = \dirname(__DIR__, 2);
        $catalog = $this->catalog($root);
        foreach ((new Questions($root.'/demo/questions.yaml', $root))->all() as $question) {
            if (null === $question->script) {
                continue;
            }
            $script = json_decode((string) file_get_contents($question->script), true, flags: \JSON_THROW_ON_ERROR);
            foreach (self::cells($script) as [$dataset, $geo]) {
                self::assertTrue($catalog->get($dataset)?->covers($geo), \sprintf('%s: %s, %s', $question->id, $dataset, $geo));
            }
        }
    }

    /** @var list<string> */
    private array $caches = [];

    protected function tearDown(): void
    {
        new \Symfony\Component\Filesystem\Filesystem()->remove($this->caches);
        parent::tearDown();
    }

    private function catalog(string $root): Catalog
    {
        $cache = $this->caches[] = sys_get_temp_dir().'/go-figure-demo-cassettes-'.bin2hex(random_bytes(4));
        mkdir($cache);

        return CatalogFixture::catalog($cache, $root.'/catalog');
    }

    /**
     * @param array<mixed> $node
     *
     * @return list<array{string, string}> every dataset and place a layout names
     */
    private static function cells(array $node): array
    {
        $cells = isset($node['dataset'], $node['geo']) && \is_string($node['dataset']) && \is_string($node['geo']) ? [[$node['dataset'], $node['geo']]] : [];
        foreach ($node as $child) {
            if (\is_array($child)) {
                $cells = [...$cells, ...self::cells($child)];
            }
        }

        return $cells;
    }
}
