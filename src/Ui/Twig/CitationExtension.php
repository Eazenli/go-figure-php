<?php

declare(strict_types=1);

namespace App\Ui\Twig;

use App\Ui\PageCitation;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * "Copy with source" in the templates: the text of a page, or of one figure, with its sources.
 */
final class CitationExtension extends AbstractExtension
{
    public function __construct(
        private readonly PageCitation $citation,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('page_citation', $this->citation->page(...))];
    }

    public function getFilters(): array
    {
        return [new TwigFilter('figure_citation', $this->citation->figure(...))];
    }
}
