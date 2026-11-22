<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\AiSettings;
use App\Ai\Cassettes;
use App\Ai\PagePart;
use App\Ai\PageStream;
use App\Ai\Questions;
use App\Import\EurostatApi;
use App\Import\Importer;
use App\Import\ProposalAgent;
use App\Ui\PageRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The day a key arrives: one command to see that everything a key unlocks works with it.
 *
 *   make key-check                 a live question, streamed and proven (a few cents)
 *   make key-check c=--agent       and the agent of /import proposing a dataset
 *   make key-check c=--record      and every example question recorded with this model: the simple
 *                                  mode then replays real answers (demo/questions.yaml is updated)
 */
#[AsCommand(
    name: 'app:key-check',
    description: 'With the key of .env.local: ask a live question, try the import agent (--agent), record the example questions (--record)',
)]
final readonly class KeyCheckCommand
{
    private const string QUESTION = 'Is Lisbon or Seville warmer in September, and which of their countries is cheaper for restaurants and hotels?';
    private const string WISH = 'arrivals of foreign tourists in hotels';

    public function __construct(
        private AiSettings $settings,
        private Cassettes $cassettes,
        private PageStream $stream,
        private PageRenderer $pages,
        private Questions $questions,
        private ProposalAgent $agent,
        private EurostatApi $eurostat,
        private Importer $importer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Also try the agent of /import (needs internet: it searches Eurostat)')]
        bool $agent = false,
        #[Option(description: 'Also record every example question with this model, and note it in demo/questions.yaml')]
        bool $record = false,
    ): int {
        if (!$this->settings->hasKey()) {
            $io->error($this->settings->problem ?? 'No key: set AI_PROVIDER, AI_MODEL and AI_API_KEY in .env.local.');

            return Command::FAILURE;
        }
        $io->title('With '.$this->settings->label());
        $failed = 0;

        $io->section('A live question');
        $io->writeln(self::QUESTION);
        try {
            $started = microtime(true);
            $page = null;
            foreach ($this->stream->parts($this->cassettes->ask($this->settings, self::QUESTION)) as $part) {
                $page = PagePart::END === $part->kind ? $part->page : $page;
            }
            if (null === $page) {
                throw new \RuntimeException('The stream ended without a page.');
            }
            $tally = $this->pages->view($page)->tally();
            // A page without one proven figure is an answer, not a good one: say so in the colour too.
            $io->{[] === $page->components || 0 === $tally['proven'] ? 'warning' : 'success'}(\sprintf('A page in %.1f s: "%s", %d components. Figures proven: %d, refused: %d, texts or periods not shown: %d.', microtime(true) - $started, $page->title, \count($page->components), $tally['proven'], $tally['refused'], $tally['hidden']));
        } catch (\Throwable $e) {
            ++$failed;
            $io->error(\sprintf('%s: %s', $e::class, $e->getMessage()));
        }

        if ($agent) {
            $io->section('The agent of /import');
            $io->writeln(self::WISH);
            try {
                $proposal = $this->agent->propose($this->settings, self::WISH);
                $problems = $this->importer->problems($proposal, $this->eurostat->structure($proposal->code));
                $io->writeln(\sprintf('Proposed: %s (%s), "%s", unit "%s".', $proposal->code, implode(', ', array_map(static fn ($c): string => $c->dimension.'='.$c->code, $proposal->dimensions)), $proposal->title, $proposal->unitLabel));
                if ([] === $problems) {
                    $io->success('The proposal passes the checks of the form of /import. Nothing was imported: the import itself still reads the values from Eurostat.');
                } else {
                    $io->warning(["The visitor would have to correct:", ...array_map(static fn (array $r): string => '- '.strtr($r[0], $r[1]), $problems)]);
                }
            } catch (\Throwable $e) {
                ++$failed;
                $io->error(\sprintf('%s: %s', $e::class, $e->getMessage()));
            }
        }

        if ($record) {
            $io->section('The example questions');
            $model = $this->settings->model.(null === $this->settings->route ? '' : ':'.$this->settings->route);
            foreach ($this->questions->all() as $question) {
                try {
                    $this->cassettes->record($question, $this->settings);
                } catch (\Throwable $e) {
                    ++$failed;
                    $io->writeln(\sprintf('<error>not recorded</error> %s (its cassette is unchanged): %s', $question->id, $e->getMessage()));
                    continue;
                }
                try {
                    $this->questions->markLive($question->id, $this->settings->provider, $model, date('Y-m-d'));
                    $io->writeln(\sprintf('<info>recorded</info> %s', $question->id));
                } catch (\Throwable $e) {
                    // The cassette is the new one already: without its line in the manifest it would not replay.
                    ++$failed;
                    $io->writeln(\sprintf('<error>half recorded</error> %s: its cassette is replaced, but demo/questions.yaml could not be updated (%s). Set its provider, model and "recorded: live" by hand, or record it again.', $question->id, $e->getMessage()));
                }
            }
            $io->note('Look at each answer on /ask before keeping it: a recorded answer is kept as the model wrote it, even a poor one. Then run make test: a cassette must replay against the prompt of today.');
        }

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
