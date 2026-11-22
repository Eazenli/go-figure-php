<?php

declare(strict_types=1);

namespace App\Ai;

use App\Layout\Page;
use App\Ui\PageRenderer;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\Stream\Delta\PartialObjectDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;

/**
 * Shows a page while the model writes it, one finished part at a time (the n−1 rule).
 *
 * Symfony AI turns the streamed JSON into partial Page objects. The last component of a partial
 * page may still be cut off: a cell whose period reads "20" while the model is writing "2024".
 * Resolving it then would show a refusal, or worse, the wrong cell. So a component is shown only
 * once the next one has started, and the last one when the stream ends.
 *
 * The title is shown early only when the model wrote it first, as the schema orders it: then it
 * is finished once a component has started. Otherwise it waits for the end, like the last
 * component.
 */
final readonly class PageStream
{
    public function __construct(
        private PageRenderer $pages,
    ) {
    }

    /**
     * A page that is already whole (a question read without a model), as the same parts: what was
     * sent to the server first, then the title and each component.
     *
     * @param string $layout the page as the layout a model would send for it, shown as written
     *
     * @return \Generator<int, PagePart>
     */
    public function whole(Page $page, string $layout, int $heading = 1): \Generator
    {
        yield new PagePart(PagePart::DELTA, $layout);
        yield new PagePart(PagePart::TITLE, $this->pages->renderTitle($page, $heading));
        foreach (\array_slice($page->components, 0, PageRenderer::MAX_COMPONENTS) as $component) {
            yield new PagePart(PagePart::COMPONENT, $this->pages->renderComponent($component, $heading));
        }
        yield new PagePart(PagePart::END, '', $page);
    }

    /**
     * @param DeferredResult $result a result invoked with `stream: true` and `response_format: Page::class`
     * @param bool           $deltas  whether to yield what the model writes, as it writes it (DELTA parts)
     * @param int            $heading the level of the page title where the page is shown (components take the next)
     *
     * @return \Generator<int, PagePart>
     *
     * @throws NoPageInStream when the model wrote no page at all
     */
    public function parts(DeferredResult $result, bool $deltas = false, int $heading = 1): \Generator
    {
        $title = false;
        $shown = 0;

        foreach ($result->asStream() as $delta) {
            if ($deltas && $delta instanceof TextDelta) {
                yield new PagePart(PagePart::DELTA, $delta->getText());
            }
            $page = $delta instanceof PartialObjectDelta ? $delta->getObject() : null;
            if (!$page instanceof Page || [] === $page->components) {
                continue;
            }

            if (!$title && 1 === preg_match('/^\s*\{\s*"title"\s*:/', $delta->getBuffer())) {
                $title = true;
                yield new PagePart(PagePart::TITLE, $this->pages->renderTitle($page, $heading));
            }
            // Every component but the last one is finished.
            for (; $shown < min(\count($page->components) - 1, PageRenderer::MAX_COMPONENTS); ++$shown) {
                yield new PagePart(PagePart::COMPONENT, $this->pages->renderComponent($page->components[$shown], $heading));
            }
        }

        // The stream has ended. Symfony AI decodes the whole page, with the same serializer as the
        // partial pages, and throws if the validation it runs on the final object fails.
        try {
            $page = $result->asObject(Page::class);
        } catch (UnexpectedResultTypeException $e) {
            throw new NoPageInStream('The model wrote no page.', previous: $e);
        }

        if (!$title) {
            yield new PagePart(PagePart::TITLE, $this->pages->renderTitle($page, $heading));
        }
        for (; $shown < min(\count($page->components), PageRenderer::MAX_COMPONENTS); ++$shown) {
            yield new PagePart(PagePart::COMPONENT, $this->pages->renderComponent($page->components[$shown], $heading));
        }
        yield new PagePart(PagePart::END, '', $page);
    }
}
