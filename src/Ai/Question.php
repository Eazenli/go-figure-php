<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An example question of /ask, replayed from its cassette.
 */
final readonly class Question
{
    public const string SCRIPTED = 'scripted';
    public const string LIVE = 'live';

    public function __construct(
        public string $id,
        public string $text,
        public string $provider,
        public string $model,
        /** Absolute path of the cassette: the request sent and the answer streamed back. */
        public string $cassette,
        /** self::LIVE: a model wrote the answer. self::SCRIPTED: it was written by hand, from $script. */
        public string $recorded,
        /** The date of the recording, shown next to a replayed page. */
        public ?string $recordedOn,
        /** Absolute path of the hand-written layout of a scripted answer. */
        public ?string $script,
        /** The language the question is asked in, and the answer written in: the page shows those of its own. */
        public string $language = 'en',
        /** The same question in the other language, so that switching the language keeps it. */
        public ?string $twin = null,
    ) {
    }

    /** The provider and model of the recording. Replaying needs no key: the cassette answers. */
    public function settings(#[\SensitiveParameter] string $apiKey = 'replay'): AiSettings
    {
        return AiSettings::of($this->provider, $this->model, $apiKey);
    }

    /** How the page says where the replayed answer comes from, in the page's language. */
    public function origin(TranslatorInterface $translator): string
    {
        return self::SCRIPTED === $this->recorded
            ? $translator->trans('Replayed: an answer written by hand in the format a model streams, to show the page without a key.')
            : $translator->trans('Replayed: the answer %model% streamed on %date%, recorded as it was.', ['%model%' => $this->settings()->label(), '%date%' => $this->recordedOn ?? $translator->trans('an unknown date')]);
    }
}
