<?php

declare(strict_types=1);

namespace App\Ai;

use App\Catalog\Catalog;
use App\Catalog\CatalogEntry;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\MultiAgent\Handoff;
use Symfony\AI\Agent\MultiAgent\MultiAgent;
use Symfony\AI\Platform\PlatformOptions;

/**
 * Decides where a free question goes, with Symfony AI's MultiAgent: an orchestrator reads the
 * question and hands it to one of three routes. One call to the model, before any page is written:
 * a question the data cannot answer goes straight to the agent that gets data, instead of being
 * answered once for nothing.
 *
 * The decision only chooses a pipeline. It writes nothing a page shows.
 *
 * Why MultiAgent here: this project was built for the Symfony AI hackathon, and the routing is where
 * we tried its orchestrator and handoffs on a real need. The three routes are stand-ins that call no
 * model (RouteAgent): MultiAgent is used as a classifier. A single call with a structured output on
 * an enum would do the same with less; MultiAgent earns its place the day a route is a real agent
 * with its own tools.
 */
#[WithMonologChannel('go_figure')]
final readonly class QuestionRouter
{
    /** Anthropic caps an answer at 1,000 tokens by default: a decision is far shorter. */
    private const array PROVIDER_OPTIONS = ['anthropic' => [PlatformOptions::MAX_OUTPUT_TOKENS => 400]];

    public function __construct(
        private Platforms $platforms,
        private Catalog $catalog,
        private LoggerInterface $logger,
    ) {
    }

    public function route(AiSettings $settings, string $question): Route
    {
        return $this->decide(
            new Agent($this->platforms->create($settings), $settings->model, name: 'go_figure_router'),
            $question,
            ['temperature' => 0] + (self::PROVIDER_OPTIONS[$settings->provider] ?? []),
        );
    }

    /**
     * @param AgentInterface       $orchestrator the agent that chooses: a model, or a stand-in in the tests
     * @param array<string, mixed> $options
     */
    public function decide(AgentInterface $orchestrator, string $question, array $options = []): Route
    {
        // The titles of the datasets added on this machine were written by a visitor or a model:
        // letters and spaces only, cut short, so that a title cannot give the orchestrator an order.
        $titles = implode('; ', array_map(static fn (CatalogEntry $entry): string => mb_substr(trim((string) preg_replace('/[^\p{L} ,-]+/u', ' ', $entry->title)), 0, 60), $this->catalog->all()));
        $routes = new MultiAgent(
            $orchestrator,
            [
                new Handoff(new RouteAgent(Route::Answer), ['a question one of these datasets answers: '.$titles]),
                new Handoff(new RouteAgent(Route::Fetch), ['a statistic by country that Eurostat or the World Bank may publish and that is not in the datasets above: the economy, businesses, energy, agriculture, the environment, transport, tourism, prices, culture']),
            ],
            // What MultiAgent falls back on: anything else, and the subjects left out on purpose.
            new RouteAgent(Route::Decline),
            'go_figure_routes',
            $this->logger,
        );
        try {
            // MultiAgent writes the question between quotes in its prompt: one line, without the
            // quotes that would end it, so that a question cannot pose as an instruction.
            $asked = trim((string) preg_replace('/[\s"“”«»`]+/u', ' ', $question));

            return Route::tryFrom(mb_strtolower(trim($routes->call($asked, $options)->asText()))) ?? Route::Answer;
        } catch (\Throwable $e) {
            // A decision that fails is no reason to answer nothing: the question is answered as before.
            $this->logger->warning('The routing of a question failed: {class}: {error}', ['class' => $e::class, 'error' => $e->getMessage()]);

            return Route::Answer;
        }
    }
}
