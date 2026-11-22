<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\NoPageInStream;
use App\Ai\PagePart;
use App\Ai\PageStream;
use App\Tests\Support\FactsFixture;
use App\Ui\PageRenderer;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A page streamed through the structured-output path of Symfony AI as the AI Bundle wires it (its
 * response format, serializer and validator subscribers on the app's event dispatcher), with a
 * scripted model in place of a provider: the text arrives in small chunks, cut anywhere, as
 * tokens do.
 */
final class PageStreamTest extends KernelTestCase
{
    private const string COMPONENTS = '"components":['
        .'{"kind":"kpi","label":"Nights in France","figure":{"type":"measured","dataset":"nights_total","geo":"FR","time":"2024"}},'
        .'{"kind":"bar","title":"Nights by country","bars":[{"type":"measured","dataset":"nights_total","geo":"FR","time":"2024"},{"type":"measured","dataset":"nights_total","geo":"ES","time":"2024"},{"type":"measured","dataset":"nights_total","geo":"DE","time":"2024"}]},'
        .'{"kind":"line","title":"Nights over time","series":[{"dataset":"nights_total","geo":"FR","from":"2019","to":"2024"}]},'
        .'{"kind":"note","segments":[{"kind":"text","text":"France counted "},{"kind":"figure","figure":{"type":"measured","dataset":"nights_total","geo":"FR","time":"2024"}},{"kind":"text","text":" nights."}]}'
        .']';
    private const string TITLE = '"title":"Nights in France and Spain"';
    private const string LAYOUT = '{'.self::TITLE.','.self::COMPONENTS.'}';

    /** The text the scripted model has sent so far. */
    private string $streamed = '';

    protected function setUp(): void
    {
        self::bootKernel();
        $path = self::getContainer()->getParameter('app.facts_path');
        FactsFixture::write($path);
    }

    public function testThePageArrivesTitleFirstThenComponentByComponent(): void
    {
        $parts = $this->parts(self::LAYOUT);

        self::assertSame(
            [PagePart::TITLE, PagePart::COMPONENT, PagePart::COMPONENT, PagePart::COMPONENT, PagePart::COMPONENT],
            array_map(static fn (array $p) => $p['part']->kind, $parts),
        );
        self::assertStringContainsString('Nights in France and Spain', $parts[0]['part']->html);
        self::assertStringContainsString('457,644,262', $parts[1]['part']->html);
        self::assertStringContainsString('gf-note', $parts[4]['part']->html);
        // Parts come out while the model is still writing: a stream, not one block at the end.
        self::assertLessThan(\strlen(self::LAYOUT), \strlen($parts[1]['streamed']));
    }

    public function testAComponentIsShownOnlyOnceTheNextOneHasStarted(): void
    {
        $parts = $this->parts(self::LAYOUT);

        foreach (\array_slice($parts, 1, -1) as $i => $p) {
            // Component $i + 1 (counting from one) is out: component $i + 2 has started.
            self::assertGreaterThanOrEqual($i + 2, self::componentsStarted($p['streamed']), $p['streamed']);
        }
        // The last one waits for the end of the stream.
        $last = end($parts);
        self::assertIsArray($last);
        self::assertSame(self::LAYOUT, $last['streamed']);
    }

    public function testACellCutOffMidStreamIsNeverResolved(): void
    {
        // Only DE is refused (not covered). A cell cut at "20" for "2024" would add a refusal.
        $html = implode('', array_map(static fn (array $p) => $p['part']->html, $this->parts(self::LAYOUT)));

        self::assertSame(1, substr_count($html, 'class="gf-refusal"'));
    }

    public function testATitleWrittenLastIsShownAtTheEndOnly(): void
    {
        // Against the schema order, as a model without constrained decoding may do.
        $layout = '{'.self::COMPONENTS.','.self::TITLE.'}';
        $parts = $this->parts($layout);
        $title = array_values(array_filter($parts, static fn (array $p) => PagePart::TITLE === $p['part']->kind))[0];

        self::assertSame($layout, $title['streamed']);
        self::assertStringContainsString('Nights in France and Spain', $title['part']->html);
        self::assertCount(5, $parts);
    }

    public function testWhatTheModelWritesAndTheWholePageCanBeFollowed(): void
    {
        $written = '';
        $end = null;
        foreach ($this->pageStream()->parts($this->invoke(self::LAYOUT, 7), deltas: true) as $part) {
            if (PagePart::DELTA === $part->kind) {
                $written .= $part->html;
            } elseif (PagePart::END === $part->kind) {
                $end = $part;
            }
        }

        self::assertSame(self::LAYOUT, $written);
        self::assertNotNull($end?->page);
        self::assertCount(4, $end->page->components);
    }

    public function testAStreamWithoutAPageSaysSo(): void
    {
        $this->expectException(NoPageInStream::class);

        $this->parts('');
    }

    /**
     * @return list<array{part: PagePart, streamed: string}> each part, with the text sent when it came out
     */
    private function parts(string $json): array
    {
        $parts = [];
        foreach ($this->pageStream()->parts($this->invoke($json, 7)) as $part) {
            if (PagePart::END !== $part->kind) {
                $parts[] = ['part' => $part, 'streamed' => $this->streamed];
            }
        }

        return $parts;
    }

    /**
     * The scripted model: $json, cut into chunks of $size bytes, through a platform that uses the
     * app's event dispatcher, so the AI Bundle's structured-output subscribers apply.
     *
     * @param positive-int $size
     */
    private function invoke(string $json, int $size): DeferredResult
    {
        $this->streamed = '';
        $dispatcher = self::getContainer()->get('event_dispatcher');

        $platform = MockPlatformFactory::createPlatform(
            fn () => new StreamResult((function () use ($json, $size): \Generator {
                foreach ('' === $json ? [] : str_split($json, $size) as $chunk) {
                    $this->streamed .= $chunk;
                    yield new TextDelta($chunk);
                }
            })()),
            eventDispatcher: $dispatcher,
        );

        return $platform->invoke('scripted-model', new MessageBag(Message::ofUser('Nights in France and Spain?')), [
            'stream' => true,
            'response_format' => \App\Layout\Page::class,
        ]);
    }

    private function pageStream(): PageStream
    {
        // Not wired to a provider yet (it waits for the key), so built here.
        return new PageStream(self::getContainer()->get(PageRenderer::class));
    }

    /** How many components have started: segments of a note also open with "kind", they are not counted. */
    private static function componentsStarted(string $json): int
    {
        return substr_count($json, '{"kind":"') - substr_count($json, '{"kind":"text"') - substr_count($json, '{"kind":"figure"') - substr_count($json, '{"kind":"period"');
    }
}
