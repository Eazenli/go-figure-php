<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\QuestionRouter;
use App\Ai\Route;
use App\Tests\Support\FactsFixture;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Result as ResultUpdate;
use Symfony\AI\Agent\MultiAgent\Handoff\Decision;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The routing of a question by Symfony AI's MultiAgent, with an orchestrator that stands in for
 * the model: what it is asked, and where each of its decisions sends the question.
 */
final class QuestionRouterTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        FactsFixture::write(self::getContainer()->getParameter('app.facts_path'));
    }

    public function testEachDecisionOfTheOrchestratorIsARoute(): void
    {
        $router = self::getContainer()->get(QuestionRouter::class);

        self::assertSame(Route::Answer, $router->decide($this->orchestrator('answer'), 'Tourism in France?'));
        self::assertSame(Route::Fetch, $router->decide($this->orchestrator('fetch'), 'The GDP of France?'));
        // No agent chosen: MultiAgent falls back, and the fallback declines.
        self::assertSame(Route::Decline, $router->decide($this->orchestrator(''), 'Who will win the election?'));
        // A name that is no route: the fallback too.
        self::assertSame(Route::Decline, $router->decide($this->orchestrator('weather'), 'Anything'));
    }

    public function testTheOrchestratorIsToldTheDatasetsAndAsksNothingElse(): void
    {
        $asked = [];
        $router = self::getContainer()->get(QuestionRouter::class);
        $router->decide($this->orchestrator('answer', $asked), 'Tourism in France?');

        // One call: the decision. The route itself asks no model.
        self::assertCount(1, $asked);
        self::assertStringContainsString('User question: "Tourism in France?"', $asked[0]['text']);
        self::assertStringContainsString('- answer: a question one of these datasets answers: ', $asked[0]['text']);
        self::assertStringContainsString('Nights spent in tourist accommodation', $asked[0]['text']);
        self::assertStringContainsString('- fetch: a statistic by country that Eurostat or the World Bank may publish', $asked[0]['text']);
        self::assertStringContainsString('- decline: fallback agent', $asked[0]['text']);
        self::assertSame(Decision::class, $asked[0]['options']['response_format']);
    }

    public function testADecisionThatFailsAnswersAsBefore(): void
    {
        $failing = new class implements AgentInterface {
            public function getName(): string
            {
                return 'failing';
            }

            public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
            {
                throw new \RuntimeException('The provider is down.');
            }
        };

        self::assertSame(Route::Answer, self::getContainer()->get(QuestionRouter::class)->decide($failing, 'Tourism in France?'));
    }

    /**
     * @param list<array{text: string, options: array<string, mixed>}> $asked what the orchestrator was asked, call by call
     */
    private function orchestrator(string $agent, array &$asked = []): AgentInterface
    {
        return new class($agent, $asked) implements AgentInterface {
            /** @param list<array{text: string, options: array<string, mixed>}> $asked */
            public function __construct(
                private readonly string $agent,
                private array &$asked, // @phpstan-ignore property.onlyWritten (read by the test, through the reference)
            ) {
            }

            public function getName(): string
            {
                return 'orchestrator';
            }

            public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
            {
                $this->asked[] = ['text' => $input instanceof MessageBag ? (string) $input->getUserMessage()?->asText() : (string) (\is_string($input) ? $input : $input->asText()), 'options' => $options];
                $decision = new Decision($this->agent, 'chosen by the test');

                return new Execution(static function () use ($decision): \Generator {
                    yield new ResultUpdate(new ObjectResult($decision));
                });
            }
        };
    }
}
