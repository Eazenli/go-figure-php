<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\AiSettings;
use App\Ai\Cassettes;
use App\Ai\Questions;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cassette',
    description: 'Record the cassette of an example question of /ask (demo/questions.yaml): the model with the key of .env.local, or the hand-written answer',
)]
final readonly class CassetteCommand
{
    public function __construct(
        private Questions $questions,
        private Cassettes $cassettes,
        private AiSettings $settings,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The id of the question')]
        string $id,
        #[Option(description: 'Record the hand-written layout of the question (its "script") instead of calling a model')]
        bool $scripted = false,
    ): int {
        $question = $this->questions->get($id);
        if (null === $question) {
            $io->error(\sprintf('There is no question "%s". The questions are: %s.', $id, implode(', ', array_keys($this->questions->all()))));

            return Command::FAILURE;
        }

        if ($scripted) {
            if (null === $question->script || !is_file($question->script)) {
                $io->error(\sprintf('Question "%s" has no script file.', $id));

                return Command::FAILURE;
            }
            try {
                $this->cassettes->record($question, $question->settings(), (string) file_get_contents($question->script));
            } catch (\Throwable $e) {
                $io->error(\sprintf('Not recorded, the cassette is unchanged: %s', $e->getMessage()));

                return Command::FAILURE;
            }
            $io->success(\sprintf('Recorded the hand-written answer in %s. In demo/questions.yaml, "recorded" must be "scripted".', $question->cassette));

            return Command::SUCCESS;
        }

        if (null !== $this->settings->problem) {
            $io->error($this->settings->problem);

            return Command::FAILURE;
        }
        if (!$this->settings->hasKey() || $this->settings->provider !== $question->provider) {
            $io->error(\sprintf('Recording "%s" calls %s: set AI_PROVIDER=%s, AI_MODEL and AI_API_KEY in .env.local.', $id, $question->provider, $question->provider));

            return Command::FAILURE;
        }
        try {
            $this->cassettes->record($question, $question->settings($this->settings->apiKey()));
        } catch (\Throwable $e) {
            $io->error(\sprintf('Not recorded, the cassette is unchanged: %s: %s', $e::class, $e->getMessage()));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Recorded %s in %s. In demo/questions.yaml, set "recorded: live" and "recorded_on: \'%s\'".', $question->settings()->label(), $question->cassette, date('Y-m-d')));

        return Command::SUCCESS;
    }
}
