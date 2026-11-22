<?php

declare(strict_types=1);

namespace App\Ai;

use App\Http\Language;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The example questions of /ask, from demo/questions.yaml.
 */
final class Questions
{
    /** @var array<string, Question>|null */
    private ?array $questions = null;

    public function __construct(
        #[Autowire('%app.questions_file%')]
        private readonly string $file,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $root,
    ) {
    }

    /**
     * @return array<string, Question> keyed by id, in the order of the file
     */
    public function all(): array
    {
        return $this->questions ??= $this->load();
    }

    /**
     * Notes in the file that a question was recorded live: its provider, its model and the date.
     * Only those lines of its block change; the comments and the other questions stay as written.
     */
    public function markLive(string $id, string $provider, string $model, string $date): void
    {
        $text = (string) file_get_contents($this->file);
        $pattern = '/^- id: '.preg_quote($id, '/').'\R(?:(?!^- id: ).*\R?)*/m';
        $changed = preg_replace_callback($pattern, static function (array $m) use ($provider, $model, $date): string {
            $block = (string) preg_replace('/^  recorded_on: .*\R/m', '', $m[0]);
            $block = (string) preg_replace('/^  provider: .*$/m', '  provider: '.$provider, $block);
            $block = (string) preg_replace('/^  model: .*$/m', '  model: "'.$model.'"', $block);

            return (string) preg_replace('/^  recorded: .*$/m', "  recorded: live\n  recorded_on: '".$date."'", $block);
        }, $text, 1, $count);
        if (1 !== $count || null === $changed) {
            throw new \LogicException(\sprintf('%s: no question "%s".', $this->file, $id));
        }
        if (false === @file_put_contents($this->file, $changed)) {
            throw new \RuntimeException(\sprintf('%s cannot be written.', $this->file));
        }
        $this->questions = null;
    }

    public function get(string $id): ?Question
    {
        return $this->all()[$id] ?? null;
    }

    /** The same question asked in a language: itself, its twin, or the question whose twin it is. */
    public function inLanguage(Question $question, string $language): ?Question
    {
        if ($question->language === $language) {
            return $question;
        }
        $twin = null !== $question->twin ? $this->get($question->twin) : null;
        if (null !== $twin && $twin->language === $language) {
            return $twin;
        }
        foreach ($this->all() as $other) {
            if ($other->twin === $question->id && $other->language === $language) {
                return $other;
            }
        }

        return null;
    }

    /**
     * The questions asked in a language, or in English when there are none in that language.
     *
     * @return array<string, Question>
     */
    public function in(string $language): array
    {
        $asked = array_filter($this->all(), static fn (Question $q): bool => $q->language === $language);

        return [] !== $asked ? $asked : array_filter($this->all(), static fn (Question $q): bool => 'en' === $q->language);
    }

    /**
     * @return array<string, Question>
     */
    private function load(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $rows = Yaml::parseFile($this->file);
        if (!\is_array($rows) || !array_is_list($rows)) {
            throw new \LogicException(\sprintf('%s: a list of questions is expected.', $this->file));
        }

        $questions = [];
        foreach ($rows as $i => $row) {
            if (!\is_array($row)) {
                throw new \LogicException(\sprintf('%s: question %d is not a map.', $this->file, $i + 1));
            }
            foreach (['id', 'text', 'provider', 'model', 'cassette', 'recorded'] as $key) {
                if (!\is_string($row[$key] ?? null) || '' === $row[$key]) {
                    throw new \LogicException(\sprintf('%s: question %d needs "%s".', $this->file, $i + 1, $key));
                }
            }
            if (!isset(AiSettings::DEFAULT_MODELS[$row['provider']])) {
                throw new \LogicException(\sprintf('%s: question "%s": unknown provider "%s".', $this->file, $row['id'], $row['provider']));
            }
            if (!\in_array($row['recorded'], [Question::LIVE, Question::SCRIPTED], true)) {
                throw new \LogicException(\sprintf('%s: question "%s": "recorded" is "live" or "scripted".', $this->file, $row['id']));
            }
            if (1 !== preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $row['id']) || isset($questions[$row['id']])) {
                throw new \LogicException(\sprintf('%s: question %d: the id must be unique, in lower case letters, digits and "-".', $this->file, $i + 1));
            }
            $language = $row['language'] ?? 'en';
            if (!\in_array($language, Language::LANGUAGES, true)) {
                throw new \LogicException(\sprintf('%s: question "%s": "language" is one of %s.', $this->file, $row['id'], implode(', ', Language::LANGUAGES)));
            }
            $script = $row['script'] ?? null;
            $on = $row['recorded_on'] ?? null;
            $questions[$row['id']] = new Question(
                $row['id'],
                $row['text'],
                $row['provider'],
                $row['model'],
                $this->root.'/'.$row['cassette'],
                $row['recorded'],
                // YAML reads an unquoted date as a timestamp.
                \is_int($on) ? gmdate('Y-m-d', $on) : (\is_string($on) ? $on : null),
                \is_string($script) ? $this->root.'/'.$script : null,
                $language,
                \is_string($row['twin'] ?? null) ? $row['twin'] : null,
            );
        }

        return $questions;
    }
}
