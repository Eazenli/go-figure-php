<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;

/**
 * The contract of a Go Figure page, told to the model by the host when the user picks the prompt.
 */
final readonly class PagePrompt
{
    public const string CONTRACT = <<<'TXT'
        Answer with a Go Figure page, built with the tools of this server:
        1. Call list_datasets, then describe_dataset for each dataset you need: check its scope, places and periods.
        2. Call render_page with a layout of tiles, bar charts, line charts, tables and notes. Each figure names one cell: {"type": "measured", "dataset": "<id>", "geo": "<place code>", "time": "<period>"}. A calculation names its cells, never its result: {"type": "computed", "op": "change" | "change_pct" | "share" | "rank", "operands": [<measured cells>]} (describe_dataset says which operations a dataset allows).
        3. Never write a value yourself. Titles, labels and texts hold words only, without any digit, number in words ("two", "second", "hundreds") or number sign (%, +, currency), and no web address: a year or a month goes only in the time, from and to fields of a cell or a series, and in the period segments of a note. The server reads every value, writes it with its unit and links it to its proof.
        4. A cell the server cannot prove is shown as a refusal: keep it, do not replace it with a guess.
        5. Once the page is shown, do not restate its figures: point the user to the page.
        TXT;

    /**
     * @param string $question The question to answer, e.g. "Is Portugal quieter than Spain for hotels?"
     *
     * @return list<PromptMessage>
     */
    #[McpPrompt(
        name: 'proven_page',
        title: 'Go Figure: answer with a proven page',
        description: 'Answers a question about European destinations with a page where every number is read and proven by the server.',
    )]
    public function prompt(string $question): array
    {
        return [new PromptMessage(Role::User, new TextContent(self::CONTRACT."\n\nQuestion: ".$question))];
    }
}
