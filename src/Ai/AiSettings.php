<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Which model answers the free questions of /ask: AI_PROVIDER, AI_MODEL and AI_API_KEY, read from
 * .env.local. Without a key, /ask only replays its example questions. The defaults below are the
 * models we target; check README.md for those actually recorded.
 *
 * The key is never shown: not in a page, not in a log, not in a dump of this object.
 */
final readonly class AiSettings
{
    /** The providers /ask supports, and the default model we target for each. */
    public const array DEFAULT_MODELS = [
        // Through Hugging Face Inference Providers: "<model>:<inference provider>", a pair pinned for
        // streamed structured output (not Groq, which does not stream it).
        'huggingface' => 'openai/gpt-oss-120b:cerebras',
        'anthropic' => 'claude-sonnet-5-5',
        'openai' => 'gpt-4.1-mini',
    ];

    /**
     * @param non-empty-string $model the model name as the provider's catalog knows it, e.g. openai/gpt-oss-120b
     */
    private function __construct(
        public string $provider,
        /** @var non-empty-string */
        public string $model,
        /** Hugging Face only: the inference provider the router sends the request to. */
        public ?string $route,
        private string $apiKey,
        /** Why the settings of .env.local cannot be used: then free questions are off, nothing else. */
        public ?string $problem = null,
    ) {
    }

    /**
     * The settings of .env.local. A mistake there never breaks the app: free questions are off,
     * /ask says why, and the example questions are still replayed.
     */
    public static function fromEnv(string $provider, string $model, #[\SensitiveParameter] string $apiKey): self
    {
        try {
            return self::of($provider, $model, $apiKey);
        } catch (\InvalidArgumentException $e) {
            return new self('huggingface', 'openai/gpt-oss-120b', 'cerebras', '', $e->getMessage());
        }
    }

    /**
     * @throws \InvalidArgumentException when the provider or the model is not usable
     */
    public static function of(string $provider, string $model, #[\SensitiveParameter] string $apiKey): self
    {
        // A value pasted with its comment ("AI_MODEL=   # …") reaches the app as the comment.
        $clean = static fn (string $v): string => str_starts_with(trim($v), '#') ? '' : trim($v);
        $provider = strtolower($clean($provider)) ?: 'huggingface';
        if (!isset(self::DEFAULT_MODELS[$provider])) {
            throw new \InvalidArgumentException(\sprintf('AI_PROVIDER "%s" is not supported: use %s.', $provider, implode(', ', array_keys(self::DEFAULT_MODELS))));
        }
        $model = $clean($model) ?: self::DEFAULT_MODELS[$provider];
        $route = null;
        if ('huggingface' === $provider && str_contains($model, ':')) {
            [$model, $route] = explode(':', $model, 2);
        }
        if ('' === $model || '' === $route) {
            throw new \InvalidArgumentException(\sprintf('AI_MODEL "%s" names no model.', $model.(null === $route ? '' : ':'.$route)));
        }

        return new self($provider, $model, $route, $clean($apiKey));
    }

    public function hasKey(): bool
    {
        return null === $this->problem && '' !== $this->apiKey;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    /** The model as shown on a page: "openai/gpt-oss-120b via cerebras (huggingface)". */
    public function label(): string
    {
        return \sprintf('%s%s (%s)', $this->model, null === $this->route ? '' : ' via '.$this->route, $this->provider);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['provider' => $this->provider, 'model' => $this->model, 'route' => $this->route, 'apiKey' => $this->hasKey() ? '(set, hidden)' : '(none)'];
    }
}
