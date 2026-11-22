<?php

declare(strict_types=1);

namespace App\Layout;

use App\Layout\Constraint\NoDigits;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * The layout contract: what the model (or a visitor, or an MCP host) asks the server to render.
 *
 * Rules kept by every class of this namespace, and checked by LayoutSchemaTest:
 * no recursion, the discriminator is the first property, nothing is nullable, no int or float.
 * Free text never carries a digit: every number on the page comes from a Figure.
 */
final readonly class Page
{
    /**
     * @param list<Component> $components
     */
    public function __construct(
        #[Schema(description: 'Page title, in plain words. No digits and no numbers in words: years and values go in figures.')]
        #[NoDigits]
        public string $title,
        public array $components,
    ) {
    }
}
