<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\AiSettings;
use App\Ai\Cassettes;
use App\Ai\Question;
use App\Ask\QuestionReader;
use App\Data\NoSnapshot;
use App\Eval\Scorer;
use App\Layout\Page;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\LocaleSwitcher;

#[AsCommand(
    name: 'app:eval',
    description: 'Measure the good cells a model names for the questions of eval/questions.jsonl: replayed from the cassettes, or live with --live',
)]
final readonly class EvalCommand
{
    public function __construct(
        private Cassettes $cassettes,
        private Scorer $scorer,
        private AiSettings $settings,
        private QuestionReader $reader,
        private LocaleSwitcher $locales,
        #[Autowire('%kernel.project_dir%')]
        private string $root,
        /** Where the cassettes and results of the evaluation go. */
        #[Autowire('%app.eval_dir%')]
        private string $dir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Call the model of .env.local and record its answers as cassettes, instead of replaying them')]
        bool $live = false,
        #[Option(description: 'Score the questions read without a model (by their words) instead of a model\'s answers: no key, no cassette')]
        bool $words = false,
        #[Option(description: 'The language of the page the questions are asked on (en, fr): with --words')]
        string $lang = 'en',
        #[Option(description: 'The questions, one JSON object per line')]
        string $questions = 'eval/questions.jsonl',
        #[Option(description: 'The lowest share of good cells that passes')]
        float $minGood = 0.8,
        #[Option(description: 'Write the results in eval/results/')]
        bool $save = false,
    ): int {
        $file = str_starts_with($questions, '/') ? $questions : $this->root.'/'.$questions;
        if (!is_file($file)) {
            $io->warning(\sprintf('No questions yet: %s.', $questions));

            return Command::SUCCESS;
        }
        if ($live && !$this->settings->hasKey()) {
            $io->error('--live calls the model: set AI_PROVIDER, AI_MODEL and AI_API_KEY in .env.local.');

            return Command::FAILURE;
        }

        if ($words) {
            $this->locales->setLocale($lang);

            return $this->byWords($io, self::read($file), $minGood, $save);
        }

        $model = $this->settings->label();
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($this->settings->provider.'-'.$this->settings->model.'-'.$this->settings->route)), '-');
        $rows = [];
        // missed: a question with no page, or a page that names none of the expected cells.
        $total = ['good' => 0, 'wrong' => 0, 'refused' => 0, 'calculations' => 0, 'texts' => 0, 'missed' => 0, 'no page' => 0, 'not recorded' => 0, 'error' => 0];
        foreach (self::read($file) as $item) {
            $question = new Question($item['id'], $item['question'], $this->settings->provider, $this->settings->model.(null === $this->settings->route ? '' : ':'.$this->settings->route), \sprintf('%s/cassettes/%s/%s.json', $this->dir, $slug, $item['id']), Question::LIVE, null, null);
            $seconds = null;
            // One question that fails (a stale cassette, a rate limit…) is reported, never the end of the run.
            try {
                if ($live) {
                    $start = microtime(true);
                    $this->cassettes->record($question, $question->settings($this->settings->apiKey()));
                    $seconds = round(microtime(true) - $start, 1);
                } elseif (!is_file($question->cassette)) {
                    ++$total['not recorded'];
                    ++$total['missed'];
                    $rows[] = [$item['id'], 'not recorded', '', '', '', '', ''];
                    continue;
                }

                $result = $this->cassettes->replay($question);
                foreach ($result->asStream() as $ignored) {
                    // read the whole answer
                }
                try {
                    $page = $result->asObject(Page::class);
                } catch (\Throwable) {
                    ++$total['no page'];
                    ++$total['missed'];
                    $rows[] = [$item['id'], 'no page', '', '', '', '', $seconds];
                    continue;
                }
                $score = $this->scorer->score($page, $item['expected']);
            } catch (NoSnapshot $e) {
                $io->error('The evaluation reads the data snapshot: '.$e->getMessage());

                return Command::FAILURE;
            } catch (\Throwable $e) {
                ++$total['error'];
                ++$total['missed'];
                $rows[] = [$item['id'], 'error: '.mb_strimwidth($e->getMessage(), 0, 80, '…'), '', '', '', '', $seconds];
                continue;
            }
            foreach ($score as $key => $n) {
                $total[$key] += $n;
            }
            if ([] !== $item['expected'] && 0 === $score['good']) {
                ++$total['missed'];
            }
            $rows[] = [$item['id'], $score['good'], $score['wrong'], $score['refused'], $score['calculations'], $score['texts'], $seconds];
        }

        $io->title(\sprintf('Evaluation of %s, %s', $model, $live ? 'live' : 'replayed'));
        $io->table(['question', 'good', 'sourced but wrong', 'refused', 'calculations', 'texts not shown', 'seconds'], $rows);
        // A missed question counts as one cell that is not good.
        $named = $total['good'] + $total['wrong'] + $total['refused'] + $total['missed'];
        $rate = 0 === $named ? 0.0 : $total['good'] / $named;
        $io->writeln(\sprintf('Good cells: %d of %d (%.0f%%), counting each missed question as one. Sourced but wrong: %d. Refused: %d. Texts not shown: %d. Missed questions: %d (no page: %d, not recorded: %d, errors: %d).', $total['good'], $named, 100 * $rate, $total['wrong'], $total['refused'], $total['texts'], $total['missed'], $total['no page'], $total['not recorded'], $total['error']));

        if ($save) {
            $out = \sprintf('%s/results/%s-%s-%s.json', $this->dir, date('Y-m-d-His'), $slug, $live ? 'live' : 'replay');
            if (!is_dir(\dirname($out))) {
                mkdir(\dirname($out), 0o777, true);
            }
            file_put_contents($out, json_encode(['model' => $model, 'mode' => $live ? 'live' : 'replay', 'date' => date('c'), 'good_rate' => round($rate, 3), 'total' => $total, 'questions' => $rows], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
            $io->writeln('Saved '.$out);
        }

        if ($total['wrong'] > 0 || $total['missed'] > 0 || $rate < $minGood) {
            $io->error(\sprintf('Below the bar: at least %.0f%% good cells, no figure sourced but wrong, no question missed.', 100 * $minGood));

            return Command::FAILURE;
        }
        $io->success('The bar is met.');

        return Command::SUCCESS;
    }

    /**
     * The questions read by their words. A question that expects no cell (the data cannot answer
     * it) is good when it gets no page: declined, not guessed.
     *
     * @param list<array{id: string, question: string, expected: list<array{dataset: string, time?: list<string>, geo?: list<string>}>}> $items
     */
    private function byWords(SymfonyStyle $io, array $items, float $minGood, bool $save): int
    {
        $rows = [];
        $total = ['good' => 0, 'wrong' => 0, 'refused' => 0, 'missed' => 0, 'incomplete' => 0, 'declined' => 0, 'answered a trap' => 0];
        foreach ($items as $item) {
            try {
                $page = $this->reader->read($item['question'])->page;
            } catch (NoSnapshot $e) {
                $io->error('The evaluation reads the data snapshot: '.$e->getMessage());

                return Command::FAILURE;
            }
            if ([] === $item['expected']) {
                // A trap: nothing in the data answers it.
                ++$total[null === $page ? 'declined' : 'answered a trap'];
                $rows[] = [$item['id'], null === $page ? 'declined' : 'ANSWERED', '', '', ''];
                continue;
            }
            if (null === $page) {
                ++$total['missed'];
                $rows[] = [$item['id'], 'no page', '', '', ''];
                continue;
            }
            $score = $this->scorer->score($page, $item['expected']);
            foreach (['good', 'wrong', 'refused'] as $key) {
                $total[$key] += $score[$key];
            }
            $missing = $this->scorer->missing($page, $item['expected']);
            if (0 === $score['good']) {
                ++$total['missed'];
            } elseif ([] !== $missing) {
                // Good cells, but not all the places a right answer names.
                ++$total['incomplete'];
            }
            $rows[] = [$item['id'], [] === $missing ? 'page' : 'INCOMPLETE: '.implode('; ', $missing), $score['good'], $score['wrong'], $score['refused']];
        }

        $io->title('Evaluation of the questions read without a model');
        $io->table(['question', 'outcome', 'good', 'sourced but wrong', 'refused'], $rows);
        $named = $total['good'] + $total['wrong'] + $total['refused'] + $total['missed'];
        $rate = 0 === $named ? 0.0 : $total['good'] / $named;
        $io->writeln(\sprintf('Good cells: %d of %d (%.0f%%), counting each missed question as one. Sourced but wrong: %d. Refused: %d. Missed questions: %d. Incomplete answers: %d. Traps declined: %d of %d.', $total['good'], $named, 100 * $rate, $total['wrong'], $total['refused'], $total['missed'], $total['incomplete'], $total['declined'], $total['declined'] + $total['answered a trap']));
        if ($save) {
            $out = \sprintf('%s/results/%s-words.json', $this->dir, date('Y-m-d-His'));
            if (!is_dir(\dirname($out))) {
                mkdir(\dirname($out), 0o777, true);
            }
            file_put_contents($out, json_encode(['model' => 'none: read by its words', 'date' => date('c'), 'good_rate' => round($rate, 3), 'total' => $total, 'questions' => $rows], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
            $io->writeln('Saved '.$out);
        }
        if ($total['wrong'] > 0 || $total['missed'] > 0 || $total['incomplete'] > 0 || $total['answered a trap'] > 0 || $rate < $minGood) {
            $io->error(\sprintf('Below the bar: at least %.0f%% good cells, no figure sourced but wrong, no question missed or half answered, no trap answered.', 100 * $minGood));

            return Command::FAILURE;
        }
        $io->success('The bar is met.');

        return Command::SUCCESS;
    }

    /**
     * @return list<array{id: string, question: string, expected: list<array{dataset: string, time?: list<string>, geo?: list<string>}>}>
     */
    private static function read(string $file): array
    {
        $items = [];
        foreach (file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $n => $line) {
            $item = json_decode($line, true);
            if (!\is_array($item) || !\is_string($item['id'] ?? null) || 1 !== preg_match('/^[a-z0-9-]{1,40}$/D', $item['id'])
                || !\is_string($item['question'] ?? null) || !\is_array($item['expected'] ?? null)) {
                throw new \InvalidArgumentException(\sprintf('%s, line %d: needs "id" (lower case letters, digits, "-"), "question" and "expected".', $file, $n + 1));
            }
            /** @var list<array{dataset: string, time?: list<string>, geo?: list<string>}> $expected */
            $expected = array_values(array_filter($item['expected'], static fn ($e): bool => \is_array($e) && \is_string($e['dataset'] ?? null)));
            $items[] = ['id' => $item['id'], 'question' => $item['question'], 'expected' => $expected];
        }

        return $items;
    }
}
