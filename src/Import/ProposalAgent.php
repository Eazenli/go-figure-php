<?php

declare(strict_types=1);

namespace App\Import;

use App\Ai\AiSettings;
use App\Ai\Platforms;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The agent of the extended mode: from what the visitor looks for, it searches Eurostat, reads the
 * dimensions of a dataset and proposes a catalog entry (a structured output). It names codes and
 * writes words; it never sees nor writes a value. The visitor reviews the proposal, and the server
 * checks it before importing anything (Importer).
 */
final readonly class ProposalAgent
{
    public const int MAX_REQUEST = 300;

    private const string INSTRUCTIONS = <<<'TXT'
        You help a visitor add one Eurostat dataset to Go Figure, a site where every number on a page is proven by the server.
        1. Call search_eurostat with a few English words to find the dataset that answers the visitor. Prefer yearly or monthly data by country.
        2. Call describe_eurostat_dataset with its code. Choose one code for every dimension it lists, the frequency (freq) included, even when it has a single code (geo and time stay free): the total rather than a breakdown unless the visitor asks for one, and a plain number rather than a rate of change.
        If the visitor asks about countries outside Europe, or Eurostat has nothing, call search_world_bank instead: to propose a World Bank indicator, answer with its id as the code (e.g. NY.GDP.MKTP.CD), no dimensions, and short words for the other fields (the server writes the title and the unit itself, from the World Bank's own words).
        3. Answer with the proposal: the code, one choice per dimension, a short title, a scope of one or two sentences, the unit label without its scale ("passengers", not "thousand passengers") and made only of words found in the title of the dataset or in the labels of the codes you chose, the theme ("other" when the dataset is not about the cost, the crowds, the weather or the access of a destination), and at most three pitfalls.
        Write in English. Never write a value, a year or any other number in the texts, not even in words: the server reads every figure from Eurostat.
        TXT;

    /** Anthropic caps an answer at 1,000 tokens by default. */
    private const array PROVIDER_OPTIONS = ['anthropic' => [PlatformOptions::MAX_OUTPUT_TOKENS => 4096]];

    public function __construct(
        private Platforms $platforms,
        private EurostatTools $tools,
    ) {
    }

    /**
     * @param (\Closure(string, string): void)|null $listen told each tool call as it starts
     *
     * @throws \Throwable whatever the provider or the tools throw: the page says the agent failed
     */
    /**
     * @param int $maxToolCalls how many times the agent may use its tools before it must propose: fewer for a question than on /import
     */
    public function propose(AiSettings $settings, string $request, ?HttpClientInterface $http = null, ?\Closure $listen = null, int $maxToolCalls = 6): Proposal
    {
        $agent = new Agent(
            $this->platforms->create($settings, $http),
            $settings->model,
            name: 'go_figure_import',
            // A tool that fails (a source that is down, an argument that is wrong) says so to the agent,
            // which tries otherwise: it does not end the answer.
            toolbox: new FaultTolerantToolbox(new Toolbox([null === $listen ? $this->tools : $this->tools->listening($listen)])),
            maxToolCalls: $maxToolCalls,
        );
        $execution = $agent->call(
            new MessageBag(Message::forSystem(self::INSTRUCTIONS), Message::ofUser($request)),
            ['response_format' => Proposal::class, 'temperature' => 0] + (self::PROVIDER_OPTIONS[$settings->provider] ?? []),
        );
        return $execution->asObject(Proposal::class);
    }
}
