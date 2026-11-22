<?php

declare(strict_types=1);

namespace App\Ai;

use App\Catalog\CatalogEntry;
use App\Import\DatasetNotAllowed;
use App\Import\DatasetNotByPlace;
use App\Import\DatasetNotFound;
use App\Import\EurostatApi;
use App\Import\Importer;
use App\Import\ImportRefused;
use App\Import\ImportStore;
use App\Import\ProposalAgent;
use App\Import\WorldBankApi;
use App\Import\WorldBankImporter;
use App\Layout\Constraint\FreeText;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * When no figure of the catalog answers a free question, goes and gets the data: an agent looks
 * for a Eurostat dataset and proposes it (the agent of /import), the server checks every code and
 * reads every value from Eurostat itself, and the question can be asked again.
 *
 * The agent never writes a value here either: it names a dataset and codes, as a model names cells.
 */
#[WithMonologChannel('go_figure')]
final readonly class DataScout
{
    private const int TRIES = 2;
    /** The tools an agent may use for one question: a search or two, the dimensions, then its proposal. */
    private const int TOOL_CALLS = 4;

    public function __construct(
        private ProposalAgent $agent,
        private Importer $importer,
        private EurostatApi $eurostat,
        private WorldBankImporter $worldBank,
        private ImportStore $store,
        #[Autowire(service: 'limiter.import_visitor')]
        private RateLimiterFactoryInterface $importVisitorLimiter,
        #[Autowire(service: 'limiter.import_all')]
        private RateLimiterFactoryInterface $importAllLimiter,
        #[Autowire(service: 'limiter.scout_visitor')]
        private RateLimiterFactoryInterface $scoutVisitorLimiter,
        #[Autowire(service: 'limiter.scout_all')]
        private RateLimiterFactoryInterface $scoutAllLimiter,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string                 $visitor the address the import limit of a visitor is counted on
     * @param \Closure(string): void $say     each step, in the language of the page, as it happens
     *
     * @return bool whether a dataset was added: the question is worth asking again
     */
    public function fetch(AiSettings $settings, string $question, string $visitor, \Closure $say): bool
    {
        $t = $this->translator;
        // Before an agent is paid for: room in the store, and the import limits of /import (one
        // visitor cannot spend the imports of the day through questions).
        if ($this->store->count() >= ImportStore::MAX_ENTRIES) {
            return false;
        }
        foreach ([$this->importVisitorLimiter->create($visitor), $this->importAllLimiter->create('all')] as $limiter) {
            if (!$limiter->consume()->isAccepted()) {
                return false;
            }
        }
        $say($t->trans('No figure of the catalog answers this. An agent looks for a dataset on Eurostat or at the World Bank…'));
        $listen = static function (string $tool, string $argument) use ($say, $t): void {
            // The words of the agent are shown as its own, and only when they hold no number; a code
            // only when it is one.
            $words = null === FreeText::problem($argument) ? mb_substr($argument, 0, 80) : '…';
            $say(match ($tool) {
                'search' => $t->trans('The agent searches Eurostat: "%words%"', ['%words%' => $words]),
                'world_bank' => $t->trans('The agent searches the World Bank: "%words%"', ['%words%' => $words]),
                default => $t->trans('The agent reads the dimensions of %code%', ['%code%' => 1 === preg_match(EurostatApi::CODE, $argument) ? $argument : '…']),
            });
        };
        $entry = null;
        $request = $question;
        // The checks of the server are the agent's feedback: one more try with the reasons of a refusal.
        for ($try = 1; null === $entry && $try <= self::TRIES; ++$try) {
            if (!$this->mayRun($visitor)) {
                // Said, not left as a step that never ends: the agent is counted before it is paid for.
                $say($t->trans('The agent was not sent: it has reached its limit of runs for now. Try again later.'));

                return false;
            }
            try {
                $proposal = $this->agent->propose($settings, $request, listen: $listen, maxToolCalls: self::TOOL_CALLS);
            } catch (\Throwable $e) {
                $this->logger->warning('The agent of /ask proposed nothing: {class}: {error}', ['class' => $e::class, 'error' => $e->getMessage()]);
                $say($t->trans('The agent found no dataset to add.'));

                return false;
            }
            $worldBank = 1 === preg_match(WorldBankApi::INDICATOR, $proposal->code);
            // Not a code of either source: nothing of it is shown.
            if (!$worldBank && 1 !== preg_match(EurostatApi::CODE, $proposal->code)) {
                $say($t->trans('The agent found no dataset to add.'));

                return false;
            }
            // An indicator of the World Bank is told by its id (NY.GDP.MKTP.CD): the server writes its
            // title and unit itself, the agent only chose it.
            $say($worldBank
                ? $t->trans('The agent proposes %code%. The server reads every value from the World Bank…', ['%code%' => $proposal->code])
                : $t->trans('The agent proposes %code%. The server checks its codes and reads every value from Eurostat…', ['%code%' => $proposal->code]));
            try {
                $entry = $worldBank
                    ? $this->worldBank->import($proposal->code, $proposal->theme)
                    : $this->importer->import($proposal, $this->eurostat->structure($proposal->code));
            } catch (DatasetNotFound|DatasetNotAllowed|DatasetNotByPlace $e) {
                // The most useful refusal to send back: the agent chooses another dataset.
                $say($t->trans('Not added: Go Figure does not take this dataset.'));
                $request = $question."\n\nYour proposal of ".$proposal->code.' was refused by the server: '.$e->getMessage().' Propose another dataset.';
            } catch (ImportRefused $e) {
                $say($t->trans('Not added:').' '.implode(' ', array_map(static fn (array $r): string => $t->trans($r[0], $r[1]), $e->reasons)));
                // Nothing the agent can correct: the store is full, or the dataset is there already.
                if ($this->store->count() >= ImportStore::MAX_ENTRIES || str_starts_with($e->reasons[0][0] ?? '', 'This choice is already imported')) {
                    return false;
                }
                // In English, as the instructions of the agent are.
                $request = $question."\n\nYour proposal of ".$proposal->code.' was refused by the server: '.$e->getMessage().' Propose again: correct it, or choose another dataset.';
            } catch (\Throwable $e) {
                $this->logger->warning('The import of /ask failed: {class}: {error}', ['class' => $e::class, 'error' => $e->getMessage()]);
                $say($t->trans('Not added: the import failed, nothing was kept.'));

                return false;
            }
        }
        if (null === $entry) {
            return false;
        }
        $this->logger->info('The agent of /ask added {id}', ['id' => $entry->id, 'code' => $proposal->code]);
        $added = static fn (CatalogEntry $e): string => $t->trans('Added: %title% (%places% places, %from% to %to%).', [
            '%title%' => $e->title,
            '%places%' => \count($e->geo),
            '%from%' => $e->time->from,
            '%to%' => $e->time->to,
        ]);
        $say($added($entry));
        // A comparison may need a second measure ("in dollars, and at purchasing power parity"): the
        // agent is asked once more, and may answer that nothing else is needed. Whatever goes wrong
        // here, the first dataset is there and the question is asked again.
        // Only when the first came at the first try: one question never pays for more than two runs.
        $second = $try > 2 ? null : $this->second($settings, $question, $entry, $proposal->code, $visitor, $listen);
        if (null !== $second) {
            $say($added($second));
        }
        $say($t->trans('The question is asked again…'));

        return true;
    }
    /** A run of the agent, counted before it is paid for: per visitor, and for everyone. */
    private function mayRun(string $visitor): bool
    {
        foreach ([$this->scoutVisitorLimiter->create($visitor), $this->scoutAllLimiter->create('all')] as $limiter) {
            if (!$limiter->consume()->isAccepted()) {
                return false;
            }
        }

        return true;
    }

    /**
     * One more dataset for the same question, when the agent says one is needed.
     *
     * @param \Closure(string, string): void $listen
     */
    private function second(AiSettings $settings, string $question, CatalogEntry $first, string $firstCode, string $visitor, \Closure $listen): ?CatalogEntry
    {
        if ($this->store->count() >= ImportStore::MAX_ENTRIES) {
            return null;
        }
        // Before the agent is paid for a second time, as for the first.
        foreach ([$this->importVisitorLimiter->create($visitor), $this->importAllLimiter->create('all')] as $limiter) {
            if (!$limiter->consume()->isAccepted()) {
                return null;
            }
        }
        if (!$this->mayRun($visitor)) {
            return null;
        }
        try {
            $proposal = $this->agent->propose($settings, $question."\n\nThe dataset \"".$first->title.'" ('.$firstCode.') was just added for this question. If answering it needs one more dataset (another measure of the same thing, or the other term of a comparison), propose that one. If it does not, answer with the code "none".', listen: $listen, maxToolCalls: self::TOOL_CALLS);
            $worldBank = 1 === preg_match(WorldBankApi::INDICATOR, $proposal->code);
            if ($proposal->code === $firstCode || (!$worldBank && 1 !== preg_match(EurostatApi::CODE, $proposal->code)) || 'none' === $proposal->code) {
                return null;
            }
            $entry = $worldBank
                ? $this->worldBank->import($proposal->code, $proposal->theme)
                : $this->importer->import($proposal, $this->eurostat->structure($proposal->code));
            $this->logger->info('The agent of /ask added {id}', ['id' => $entry->id, 'code' => $proposal->code]);

            return $entry;
        } catch (\Throwable $e) {
            $this->logger->info('No second dataset for /ask: {class}: {error}', ['class' => $e::class, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
