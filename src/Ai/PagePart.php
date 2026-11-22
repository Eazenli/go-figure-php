<?php

declare(strict_types=1);

namespace App\Ai;

use App\Layout\Page;

/**
 * A part of a page that is ready to show while the model is still writing the rest.
 */
final readonly class PagePart
{
    public const string TITLE = 'title';
    public const string COMPONENT = 'component';
    /** What the model just wrote, as raw text ("What the model writes" on /ask); asked for with $deltas. */
    public const string DELTA = 'delta';
    /** The stream has ended: $page is the whole page, as the model wrote it. */
    public const string END = 'end';

    public function __construct(
        /** One of the constants above */
        public string $kind,
        /**
         * TITLE and COMPONENT: the rendered HTML, every figure in it already resolved to a proof or a
         * refusal. DELTA: the raw text, never shown as HTML. END: empty.
         */
        public string $html,
        public ?Page $page = null,
    ) {
    }
}
