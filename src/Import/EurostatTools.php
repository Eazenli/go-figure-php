<?php

declare(strict_types=1);

namespace App\Import;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * What the import agent may do: look for a Eurostat dataset, and read its dimensions. Nothing else,
 * and nothing that returns a value: the agent proposes codes, the server reads the figures.
 * The MCP tools of the extended mode (App\Mcp\ImportTools) say the same through MCP.
 */
#[AsTool('search_eurostat', 'Looks for Eurostat datasets whose title holds the words of the query. Returns codes and titles, never values.', method: 'search')]
#[AsTool('search_world_bank', 'Looks for World Bank indicators (World Development Indicators: the whole world, by country and year) whose name holds the words of the query. Returns ids and names, never values.', method: 'searchWorldBank')]
#[AsTool('describe_eurostat_dataset', 'Lists the dimensions of a Eurostat dataset and their codes, to choose one code for each (geo and time stay free).', method: 'describe')]
final readonly class EurostatTools
{
    /**
     * @param (\Closure(string, string): void)|null $listen told each call as it starts ("search" and
     *                                                       its words, "describe" and its code)
     */
    public function __construct(
        private EurostatApi $eurostat,
        private WorldBankApi $worldBank,
        private ?\Closure $listen = null,
    ) {
    }

    /**
     * The same tools, telling each call as it starts: /ask shows what its agent is doing.
     *
     * @param \Closure(string, string): void $listen
     */
    public function listening(\Closure $listen): self
    {
        return new self($this->eurostat, $this->worldBank, $listen);
    }

    /**
     * @param string $query a few English words, e.g. "arrivals tourist accommodation"
     */
    public function search(string $query): string
    {
        if (null !== $this->listen) {
            ($this->listen)('search', $query);
        }
        try {
            $found = $this->eurostat->search($query);
        } catch (EurostatUnavailable) {
            return 'Eurostat does not answer right now.';
        }
        if ([] === $found) {
            return 'No Eurostat dataset has these words in its title. Try other words, in English.';
        }

        return "Eurostat datasets (code: title, years):\n".implode("\n", array_map(
            static fn (array $d): string => \sprintf('- %s: %s (%s to %s)', $d['code'], $d['title'], $d['from'], $d['to']),
            $found,
        ));
    }

    /**
     * @param string $query a few English words, e.g. "forest area" or "electricity renewable"
     */
    public function searchWorldBank(string $query): string
    {
        if (null !== $this->listen) {
            ($this->listen)('world_bank', $query);
        }
        try {
            $found = $this->worldBank->search($query);
        } catch (WorldBankUnavailable) {
            return 'The World Bank does not answer right now.';
        }
        if ([] === $found) {
            return 'No World Bank indicator has these words in its name. Try other words, in English.';
        }

        return "World Bank indicators (id: name):\n".implode("\n", array_map(static fn (array $i): string => \sprintf('- %s: %s', $i['id'], $i['name']), $found));
    }

    /**
     * @param string $code a Eurostat dataset code, e.g. tour_occ_arnat
     */
    public function describe(string $code): string
    {
        if (null !== $this->listen) {
            ($this->listen)('describe', $code);
        }
        try {
            $structure = $this->eurostat->structure($code);

            // Said with the dimensions: the import checks the words of the unit label.
            return $structure->describe().(null === $structure->dimension('unit') ? "\n\nThis dataset has no dimension \"unit\": its unit label must use only words of its title or of the labels of the codes chosen." : '');
        } catch (DatasetNotFound|DatasetNotByPlace|DatasetNotAllowed $e) {
            return $e->getMessage();
        } catch (EurostatUnavailable) {
            return 'Eurostat does not answer right now.';
        }
    }
}
