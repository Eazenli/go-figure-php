<?php

declare(strict_types=1);

namespace App\Ai;

use App\Catalog\Catalog;
use App\Mcp\CatalogTools;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * What /ask sends to the model: the contract of a page and the whole catalog, then the question.
 * The answer is a layout (the response format is the page schema), never a value.
 *
 * A recorded cassette holds this exact request: changing the prompt, the schema or the catalog
 * makes the replay fail, so a stale answer is never shown.
 */
final readonly class AskPrompt
{
    public const string CONTRACT = <<<'TXT'
        You answer questions about European statistics (prices, tourism, transport, weather, and the datasets added below) with a Go Figure page: a layout of tiles, bar charts, line charts, tables and notes, written as JSON that follows the response schema.
        1. Each figure names one cell of the datasets below: {"type": "measured", "dataset": "<id>", "geo": "<place code>", "time": "<period>"}. A line chart names series: {"dataset": "<id>", "geo": "<place code>", "from": "<period>", "to": "<period>"}. A calculation names its cells, never its result: {"type": "computed", "op": "<operation>", "operands": [<measured cells>]}, with the operations each dataset allows: change (one place of one dataset at two periods: the later minus the earlier, given in that order), change_pct (the same, in percent of the earlier), share (a part, then its whole: two datasets of the same source and unit, at the same place and period, in percent), rank (the place of the first cell among all the cells given, for one dataset and one period, highest first).
        2. Never write a value yourself. Titles, labels and texts hold words only, without any digit, number in words in any language ("two", "three", "second", "hundreds", "thousand", "percent", "deux", "trois", "les deux", "seconde", "centaines", "pour cent") or number sign (%, +, currency): write "these countries", "the other chart", "relative to its population"; the server writes the unit of a figure itself, and no web address: a year or a month goes only in the time, from and to fields of a cell or a series, and in the period segments of a note. The server reads every value, writes it with its unit and links it to its proof.
        3. Pick the dataset whose scope matches the question, and read its pitfalls. A cell the server cannot prove is shown as a refusal: never guess one.
        4. If the datasets cannot answer the question, say so in a short note, with no figure.
        5. Start with a note that answers the question in a sentence or two, as one would say it: each figure of that sentence is a figure segment and each year a period segment, never typed. Then the charts that show it.
        6. When the words of the question fit two datasets that count different things (hotels alone, or all tourist accommodation), show both and say in a note which is which.
        7. Keep it short: a title and a few components. Write in French if the question is in French, in English otherwise.
        TXT;

    public function __construct(
        private Catalog $catalog,
    ) {
    }

    /**
     * @param bool $withImports with the datasets imported on this machine: for a live question only,
     *                          the example questions were recorded with the shipped catalog
     */
    public function system(bool $withImports = false): string
    {
        $entries = array_map(CatalogTools::brief(...), array_values($withImports ? $this->catalog->all() : $this->catalog->shipped()));

        return self::CONTRACT."\n\nThe datasets:\n\n".implode("\n\n", $entries);
    }

    public function messages(string $question, bool $withImports = false): MessageBag
    {
        return new MessageBag(Message::forSystem($this->system($withImports)), Message::ofUser($question));
    }
}
