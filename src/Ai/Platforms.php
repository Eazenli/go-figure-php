<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\AI\Platform\Bridge\Anthropic\Factory as Anthropic;
use Symfony\AI\Platform\Bridge\HuggingFace\Factory as HuggingFace;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAi;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the Symfony AI platform of the chosen provider, at run time: the provider and the key come
 * from .env.local, and a replayed question passes its own HTTP client (the cassette).
 *
 * The app's event dispatcher goes to every platform: it carries the AI Bundle's structured-output
 * subscribers, which turn `response_format: Page::class` into a schema and the answer into a Page.
 */
final readonly class Platforms
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private HttpClientInterface $http,
    ) {
    }

    /**
     * Not wrapped in the AI Bundle's TraceablePlatform for the profiler: in 1.0.0-BETA1, it breaks
     * a streamed structured output (asObject() fails after the stream), fixed upstream by
     * symfony/ai#2843. The profiler's HTTP Client panel shows the call instead.
     */
    public function create(AiSettings $settings, ?HttpClientInterface $http = null): PlatformInterface
    {
        $http ??= $this->http;

        return match ($settings->provider) {
            'huggingface' => HuggingFace::createPlatform($settings->apiKey(), $settings->route, $http, eventDispatcher: $this->dispatcher),
            'anthropic' => Anthropic::createPlatform($settings->apiKey(), $http, eventDispatcher: $this->dispatcher),
            'openai' => OpenAi::createPlatform($settings->apiKey(), $http, eventDispatcher: $this->dispatcher),
            default => throw new \LogicException(\sprintf('No platform for "%s".', $settings->provider)),
        };
    }
}
