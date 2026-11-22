<?php

declare(strict_types=1);

namespace App\Ai;

use App\Layout\Page;
use Symfony\AI\Platform\PlatformOptions;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Test\Replay\CassetteHttpClient;
use Symfony\AI\Platform\Test\Replay\HttpCassette;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks a model, live or from a cassette.
 *
 * A replay goes through the real provider bridge, its parser and the AI Bundle's structured
 * output, fed with the recorded bytes: only the network is left out. Each replay reads its cassette
 * afresh, and the request must be the one recorded: a change of prompt, schema or catalog fails
 * the replay instead of showing a stale answer.
 *
 * CassetteHttpClient comes from Symfony AI's test tools (Test\Replay): it is used here on purpose,
 * to replay a demo without a key, rather than the RecordingProvider, which could not replay a stream when this was written.
 */
final readonly class Cassettes
{
    public const array OPTIONS = ['stream' => true, 'response_format' => Page::class, 'temperature' => 0];
    /** Anthropic caps the answer at 1,000 tokens by default: a page can be longer. */
    private const array PROVIDER_OPTIONS = ['anthropic' => [PlatformOptions::MAX_OUTPUT_TOKENS => 4096]];

    public function __construct(
        private Platforms $platforms,
        private AskPrompt $prompt,
        private HttpClientInterface $http,
    ) {
    }

    /** A live question, with the key of .env.local. */
    public function ask(AiSettings $settings, string $question, ?HttpClientInterface $http = null, bool $withImports = false): DeferredResult
    {
        return $this->platforms->create($settings, $http)->invoke($settings->model, $this->prompt->messages($question, $withImports), self::OPTIONS + (self::PROVIDER_OPTIONS[$settings->provider] ?? []));
    }

    /** An example question, answered by its cassette: no key, no network. */
    public function replay(Question $question): DeferredResult
    {
        if (!is_file($question->cassette)) {
            throw new \RuntimeException(\sprintf('The cassette of "%s" is missing: record it with app:cassette.', $question->id));
        }

        return $this->ask($question->settings(), $question->text, new CassetteHttpClient(new HttpCassette($question->cassette), record: false));
    }

    /**
     * Records the cassette of a question: what the model streams, or, with $script, a hand-written
     * layout streamed in the format of the provider (chat completion chunks, as Hugging Face and
     * OpenAI-compatible providers send them).
     *
     * The recording goes to a temporary file, and replaces the cassette only once the answer has
     * been read whole and decoded as a page: a wrong key, a rate limit or no network never destroys
     * a cassette that worked (a live recording costs a paid call).
     */
    public function record(Question $question, AiSettings $settings, ?string $script = null): void
    {
        if (null !== $script && 'huggingface' !== $settings->provider) {
            throw new \LogicException('A scripted answer is streamed as chat completion chunks: use the huggingface provider.');
        }
        $real = null === $script ? $this->http : new MockHttpClient(new MockResponse(self::chunks($script), [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        if (!is_dir(\dirname($question->cassette))) {
            mkdir(\dirname($question->cassette), 0o777, true);
        }
        $draft = $question->cassette.'.recording';
        if (is_file($draft)) {
            unlink($draft);
        }
        try {
            $result = $this->ask($settings, $question->text, new CassetteHttpClient(new HttpCassette($draft), $real, record: true));
            foreach ($result->asStream() as $ignored) {
                // The cassette is written when the response is read.
            }
            $result->asObject(Page::class);
        } catch (\Throwable $e) {
            if (is_file($draft)) {
                unlink($draft);
            }

            throw $e;
        }
        self::withoutAccountHeaders($draft);
        rename($draft, $question->cassette);
    }

    /**
     * A provider answers with headers that name the account of the key (Anthropic: the organization
     * and the workspace). A cassette is public: they are masked, as the key already is.
     */
    private static function withoutAccountHeaders(string $file): void
    {
        $masked = preg_replace('/("anthropic-(?:organization|workspace)-id":\s*\[\s*")[^"]*(")/', '$1[redacted]$2', (string) file_get_contents($file));
        if (\is_string($masked)) {
            file_put_contents($file, $masked);
        }
    }

    /**
     * A layout, cut into small chunks as a model streams tokens.
     */
    private static function chunks(string $layout): string
    {
        $json = json_encode(json_decode($layout, flags: \JSON_THROW_ON_ERROR), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $events = '';
        foreach (mb_str_split($json, 8) as $i => $chunk) {
            $events .= 'data: '.json_encode([
                'id' => 'scripted',
                'object' => 'chat.completion.chunk',
                'choices' => [['index' => 0, 'delta' => 0 === $i ? ['role' => 'assistant', 'content' => $chunk] : ['content' => $chunk], 'finish_reason' => null]],
            ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n\n";
        }

        return $events
            .'data: {"id":"scripted","object":"chat.completion.chunk","choices":[{"index":0,"delta":{},"finish_reason":"stop"}]}'."\n\n"
            ."data: [DONE]\n\n";
    }
}
