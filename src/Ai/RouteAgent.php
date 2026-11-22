<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Result as ResultUpdate;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\TextResult;

/**
 * One way out of the routing of a question: the agent Symfony AI's MultiAgent hands the question
 * to. It asks no model: it answers with the name of its route, and the page of the answer is then
 * written, streamed and proven by the pipeline of that route (the page stream, the data scout).
 */
final readonly class RouteAgent implements AgentInterface
{
    public function __construct(
        private Route $route,
    ) {
    }

    public function getName(): string
    {
        return $this->route->value;
    }

    public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
    {
        $route = $this->route->value;

        return new Execution(static function () use ($route): \Generator {
            yield new ResultUpdate(new TextResult($route));
        });
    }
}
