<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AiSettings;
use App\Ai\Cassettes;
use App\Ai\DataScout;
use App\Ai\NoPageInStream;
use App\Ai\PagePart;
use App\Ai\PageStream;
use App\Ai\QuestionRouter;
use App\Ai\Questions;
use App\Ai\Route as Way;
use App\Ask\NextQuestions;
use App\Ask\QuestionReader;
use App\Composer\PageComposer;
use App\Data\NoSnapshot;
use App\Home\HomeContent;
use App\Http\SameOrigin;
use App\Layout\Constraint\FreeText;
use App\Layout\Note;
use App\Layout\Page;
use App\Layout\Text;
use App\Proof\ComputedProof;
use App\Proof\MeasuredProof;
use App\Ui\View\PageView;
use App\Ui\PageLink;
use App\Ui\PageLog;
use App\Ui\PageRenderer;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A question in, a page out, built while the model writes it: the first page of the site.
 *
 * The example questions replay their cassette, without a key. A free question calls the model of
 * .env.local: only with a key, from this site's own pages, within a rate limit; without a key it is
 * read by its words. Either way the server shows only what it proves.
 *
 * The page is rendered whole (without JavaScript), or sent part by part as server-sent events
 * (/ask/stream), with what the model writes shown as it comes.
 */
#[WithMonologChannel('go_figure')]
final class AskController extends AbstractController
{
    /** What a model is told when the page refused a text of its answer: in English, as its instructions are. */
    private const string CORRECTION = 'The server refused part of your page: a text held a number, a number in words, a number sign or a web address, and was not shown. Write the whole page again. Every value is a figure segment and every year or month a period segment; a text holds words only.';
    /** The longest a live answer may take, agent included. */
    private const int LIVE_SECONDS = 180;
    private const int MAX_QUESTION = 300;

    public function __construct(
        private readonly Questions $questions,
        private readonly Cassettes $cassettes,
        private readonly AiSettings $settings,
        private readonly PageStream $stream,
        private readonly DataScout $scout,
        private readonly PageRenderer $pages,
        private readonly PageLog $log,
        private readonly PageLink $links,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly LocaleSwitcher $locales,
        private readonly HomeContent $home,
        private readonly QuestionReader $reader,
        private readonly QuestionRouter $router,
        private readonly NextQuestions $titles,
        private readonly RateLimiterFactoryInterface $askVisitorLimiter,
        private readonly RateLimiterFactoryInterface $askAllLimiter,
        #[Autowire('%app.replay_pause_us%')]
        private readonly int $pause,
    ) {
    }

    /**
     * The one page of the site: the question, its answer, then how it works. /ask is its former
     * address: a link to it lands here.
     */
    #[Route('/', name: 'home', methods: ['GET', 'POST'])]
    #[Route('/ask', name: 'ask', methods: ['GET', 'POST'])]
    public function page(Request $request): Response
    {
        if ('ask' === $request->attributes->get('_route') && !$request->isMethod('POST')) {
            return $this->redirectToRoute('home', $request->query->all() + ($request->query->has('q') ? ['_fragment' => 'result'] : []));
        }
        // The example questions of the page's language: asked, and answered, in that language.
        $view = ['questions' => $this->questions->in($request->getLocale()), 'live' => $this->settings->hasKey(), 'model' => $this->settings->label(), 'max' => self::MAX_QUESTION, 'problem_settings' => $this->settings->problem] + $this->home->view($request->getLocale());
        // Questions to click and what can be asked without a model: shown with a key too, folded.
        try {
            $view += ['by_words' => $this->reader->examples(), 'knows' => $this->reader->knows()];
        } catch (NoSnapshot) {
            // No data yet: nothing to click, the page still opens.
        }
        $typedOnly = !$request->isMethod('POST') && !$request->query->has('q');
        if ($typedOnly && (($this->settings->hasKey() && !$request->query->getBoolean('words')) || '' === trim($request->query->getString('ask')))) {
            // A question typed on the home page: shown in the form, and asked from this page by its
            // script (the visitor presses the button without it). Never asked by the link alone.
            $typed = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $request->query->getString('ask'))), 0, self::MAX_QUESTION);

            return $this->render('home.html.twig', $view + ['question' => '', 'typed' => $typed, 'current' => null]);
        }

        // An example in the other language (the visitor just switched): the same question in this one.
        if (!$request->isMethod('POST') && null !== ($asked = $this->questions->get($request->query->getString('q')))) {
            $same = $this->questions->inLanguage($asked, $request->getLocale());
            if (null !== $same && $same !== $asked) {
                return $this->redirectToRoute('home', ['q' => $same->id, '_fragment' => 'result']);
            }
        }
        $ask = $this->start($request);
        if ($ask instanceof Response) {
            // Why a question gets no answer, said on the page itself (the stream says it as text).
            if (str_starts_with((string) $ask->headers->get('Content-Type'), 'text/plain')) {
                $asked = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $request->isMethod('POST') ? $request->request->getString('question') : $request->query->getString('ask'))), 0, self::MAX_QUESTION);

                return $this->render('home.html.twig', $view + ['question' => $asked, 'current' => null, 'words' => !$this->settings->hasKey() || $request->request->getBoolean('words') || $request->query->getBoolean('words'), 'problem' => $ask->getContent()], new Response(status: $ask->getStatusCode(), headers: $ask->headers->has('Retry-After') ? ['Retry-After' => (string) $ask->headers->get('Retry-After')] : []));
            }

            return $ask;
        }
        $html = '';
        $raw = '';
        $tally = null;
        $problem = null;
        try {
            foreach (($ask['parts'])() as $part) {
                if (PagePart::DELTA === $part->kind) {
                    $raw .= $part->html; // what the model wrote, shown beside the page as plain text, not checked
                } elseif (PagePart::END === $part->kind) {
                    $tally = $this->ended($part, $ask['refine'] ?? null, $ask['model'] ? $ask['question'] : null);
                    $html .= $tally['credits'];
                } else {
                    $html .= $part->html;
                }
            }
        } catch (\Throwable $e) {
            $problem = $this->problem($e);
        }

        // What the model wrote, laid out when it is JSON as a whole: easier to read, still its own words.
        try {
            $raw = json_encode(json_decode($raw, flags: \JSON_THROW_ON_ERROR), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // left as written
        }

        return $this->render('home.html.twig', $view + [
            'question' => $ask['question'],
            'current' => $ask['id'],
            'words' => !$ask['model'],
            'left_out' => $ask['left_out'] ?? null,
            'question_language' => $ask['language'],
            'origin' => $ask['origin'],
            'html' => $html,
            'raw' => $raw,
            'tally' => $tally,
            'problem' => $problem,
        ]);
    }

    #[Route('/ask/stream', name: 'ask_stream', methods: ['GET', 'POST'])]
    public function events(Request $request): Response
    {
        $ask = $this->start($request);
        if ($ask instanceof Response) {
            return $ask;
        }

        // Symfony sets every service back to the default language once the request is handled, before
        // a streamed response runs: the stream sets the visitor's language again for itself.
        $locale = $request->getLocale();
        $visitor = $request->getClientIp() ?? 'unknown';
        $response = new StreamedResponse(function () use ($ask, $locale, $visitor): void {
            $this->locales->runWithLocale($locale, function () use ($ask, $visitor): void {
                $send = static function (string $event, mixed $data): void {
                    echo 'event: ', $event, "\n", 'data: ', json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), "\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                };
                $send('origin', $ask['origin']);
                if (null !== ($ask['left_out'] ?? null)) {
                    $send('leftout', $ask['left_out']);
                }
                // A model and its agent take longer than a page: the decision, a few searches, an
                // import, then the answer. The limit of a request would cut the stream in the middle.
                if ($ask['model'] && !$ask['replay']) {
                    set_time_limit(self::LIVE_SECONDS);
                }
                try {
                    // A question for a model is routed first (Symfony AI's MultiAgent): answered from
                    // the data here, sent to the agent that gets data, or declined. One call, before
                    // any page is written.
                    $route = Way::Answer;
                    if ($ask['model'] && !$ask['replay']) {
                        $route = $this->router->route($this->settings, $ask['question']);
                        if (Way::Decline === $route) {
                            $send('problem', $this->translator->trans('ask.declined'));

                            return;
                        }
                        if (Way::Fetch === $route) {
                            $this->scout->fetch($this->settings, $ask['question'], $visitor, static fn (string $step) => $send('step', $step));
                        }
                    }
                    $end = null;
                    $last = null;
                    foreach (($ask['parts'])() as $part) {
                        if (PagePart::DELTA === $part->kind && $ask['replay'] && $this->pause > 0) {
                            usleep($this->pause); // a replay keeps the pace of a model
                        }
                        if (PagePart::END === $part->kind) {
                            $last = $part->page;
                            $end = $this->ended($part, $ask['refine'] ?? null, $ask['model'] ? $ask['question'] : null);
                        } else {
                            $send($part->kind, $part->html);
                        }
                    }
                    // No agent after an answer without a figure: the router already sends to the agent
                    // the questions the data cannot answer, and an answer that names no cell is most
                    // often a place the data does not have, which no other dataset brings.
                    if ($ask['model'] && !$ask['replay'] && null !== $end && $end['hidden'] > 0 && '' !== self::refusedTexts($last)) {
                        // The model wrote a number itself, which the page did not show: the refusal goes
                        // back to it once, and it writes its answer again. Seen on the page as it happens.
                        $send('step', $this->translator->trans('The server refused a number the model wrote itself. The model writes its answer again…'));
                        $send('reset', null);
                        $again = $this->stream->parts($this->cassettes->ask($this->settings, $ask['question']."\n\n".self::CORRECTION.self::refusedTexts($last), withImports: true), deltas: true, heading: 3);
                        foreach ($again as $part) {
                            $send($part->kind, PagePart::END === $part->kind ? $this->ended($part, null, $ask['question']) : $part->html);
                        }
                    } elseif (null !== $end) {
                        $send('end', $end);
                    }
                } catch (\Throwable $e) {
                    $send('problem', $this->problem($e));
                }
            });
        });
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * The question asked, and how to get its answer; or why it is not asked.
     *
     * @return array{id: ?string, question: string, language: ?string, origin: string, replay: bool, model: bool, left_out?: ?string, refine?: array{indicators: list<array{label: string, ask: string}>, months: list<array{label: string, ask: string, current: bool}>, places: list<array{label: string, ask: string, current: bool}>}, parts: \Closure(): \Generator<int, PagePart>}|Response
     */
    private function start(Request $request): array|Response
    {
        if (!$request->isMethod('POST') && $request->query->has('q')) {
            $question = $this->questions->get($request->query->getString('q'));
            if (null === $question) {
                throw $this->createNotFoundException('There is no such example question.');
            }

            return [
                'id' => $question->id,
                'question' => $question->text,
                'language' => $question->language,
                'origin' => $question->origin($this->translator),
                'replay' => true,
                'model' => true,
                'parts' => fn (): \Generator => $this->stream->parts($this->cassettes->replay($question), deltas: true, heading: 3),
            ];
        }

        // Without a key no model is asked and nothing is spent: the words of the question are
        // matched to the data of this machine, from the form or from a link (?ask=…).
        // The same when the visitor asks for it ("Answer without AI"), key or not.
        if (!$this->settings->hasKey() || $request->request->getBoolean('words') || $request->query->getBoolean('words')) {
            return $this->read($request->isMethod('POST') ? $request->request->getString('question') : $request->query->getString('ask'));
        }
        if (!$request->isMethod('POST')) {
            throw $this->createNotFoundException('A question to a model is asked from the page.');
        }

        // The key belongs to whoever runs the app: a free question comes from this site's own form.
        if (!SameOrigin::allows($request)) {
            return new Response($this->translator->trans('Questions are asked from the page itself.'), Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/plain']);
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $request->request->getString('question')));
        if ('' === $text || mb_strlen($text) > self::MAX_QUESTION) {
            return new Response($this->translator->trans('Ask a question of at most %max% characters.', ['%max%' => self::MAX_QUESTION]), Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => 'text/plain']);
        }
        foreach ([$this->askVisitorLimiter->create($request->getClientIp() ?? 'unknown'), $this->askAllLimiter->create('all')] as $limiter) {
            $limit = $limiter->consume();
            if (!$limit->isAccepted()) {
                return new Response($this->translator->trans('Too many questions: try again after %time%.', ['%time%' => $limit->getRetryAfter()->format('H:i T')]), Response::HTTP_TOO_MANY_REQUESTS, ['Content-Type' => 'text/plain', 'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]);
            }
        }
        $this->logger->info('Free question to {model}', ['model' => $this->settings->label()]);

        return [
            'id' => null,
            'question' => $text,
            'language' => null,
            'origin' => $this->translator->trans('Live: answered by %model%.', ['%model%' => $this->settings->label()]),
            'replay' => false,
            'model' => true,
            // A live question also sees the datasets imported on this machine (/import).
            'parts' => fn (): \Generator => $this->stream->parts($this->cassettes->ask($this->settings, $text, withImports: true), deltas: true, heading: 3),
        ];
    }

    /**
     * The texts of a page that the page did not show, quoted for the model that wrote them: it
     * cannot correct what it is not shown. In English, as its instructions are.
     */
    private static function refusedTexts(?Page $page): string
    {
        if (null === $page) {
            return '';
        }
        $texts = FreeText::problem($page->title) ? [$page->title] : [];
        foreach ($page->components as $component) {
            foreach (['title', 'label'] as $property) {
                if (isset($component->{$property}) && \is_string($component->{$property}) && null !== FreeText::problem($component->{$property})) {
                    $texts[] = $component->{$property};
                }
            }
            if ($component instanceof Note) {
                foreach ($component->segments as $segment) {
                    if ($segment instanceof Text && null !== FreeText::problem($segment->text)) {
                        $texts[] = $segment->text;
                    }
                }
            }
        }

        return [] === $texts ? '' : ' The texts refused: '.implode(' | ', array_map(static fn (string $text): string => '"'.mb_substr($text, 0, 200).'"', \array_slice($texts, 0, 6))).'.';
    }

    /**
     * Under an answer of a model, the next questions one click away: another place, another month,
     * another figure for the same places. Built from what the page shows (its datasets, its places,
     * its month or its year), and answered without a model: nothing is remembered, nothing is spent.
     *
     * @return array{indicators: list<array{label: string, ask: string}>, months: list<array{label: string, ask: string, current: bool}>, places: list<array{label: string, ask: string, current: bool}>}|null
     */
    private function next(PageView $view): ?array
    {
        $datasets = $places = $times = [];
        foreach ($view->parts() as $part) {
            foreach (match (true) {
                $part instanceof MeasuredProof => [$part],
                $part instanceof ComputedProof => $part->operands,
                default => [],
            } as $cell) {
                $datasets[$cell->fact->dataset] = true;
                $places[$cell->fact->geo] = true;
                $times[$cell->fact->time] = true;
            }
        }
        if ([] === $datasets || \count($datasets) > 3 || \count($places) > 6) {
            return null;
        }
        // The period, when the page has one: a month of the year, a year, a month of a year.
        $when = '';
        if (1 === \count($times)) {
            $time = (string) array_key_first($times);
            $when = match (true) {
                1 === preg_match('/^M(\d{2})$/D', $time, $m) => ' in '.PageComposer::monthName($m[1], 'en'),
                1 === preg_match('/^\d{4}(-\d{2})?$/D', $time) => ' '.$time,
                default => '',
            };
        }
        try {
            $reading = $this->reader->read(\sprintf('%s: %s%s', implode(', ', array_map($this->titles->datasetTitle(...), array_map(strval(...), array_keys($datasets)))), implode(', ', array_map($this->titles->placeName(...), array_map(strval(...), array_keys($places)))), $when));
        } catch (NoSnapshot) {
            return null;
        }

        return null === $reading->page ? null : ['indicators' => $reading->indicators, 'months' => $reading->months, 'places' => $reading->places];
    }

    /**
     * The right cell, checked without a model: the words of the question are read as they are
     * without one, and the indicators they name that the model's page does not show are said. The
     * proof says a value is the cell's; this says the cell may not be the one asked about.
     *
     * @return list<string> the titles of those indicators
     */
    private function also(string $question, PageView $view): array
    {
        $used = [];
        foreach ($view->parts() as $part) {
            foreach (match (true) {
                $part instanceof MeasuredProof => [$part],
                $part instanceof ComputedProof => $part->operands,
                default => [],
            } as $cell) {
                $used[$cell->fact->dataset] = true;
            }
        }
        try {
            $reading = $this->reader->read($question, refine: false);
        } catch (NoSnapshot) {
            return [];
        }

        return null === $reading->page ? [] : array_values(array_diff_key($reading->datasets, $used));
    }

    /**
     * A question read without a model: its page, or what the data has when its words name none.
     *
     * @return array{id: null, question: string, language: null, origin: string, replay: false, model: false, left_out: ?string, refine: array{indicators: list<array{label: string, ask: string}>, months: list<array{label: string, ask: string, current: bool}>, places: list<array{label: string, ask: string, current: bool}>}, parts: \Closure(): \Generator<int, PagePart>}|Response
     */
    private function read(string $question): array|Response
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $question));
        if ('' === $text || mb_strlen($text) > self::MAX_QUESTION) {
            return new Response($this->translator->trans('Ask a question of at most %max% characters.', ['%max%' => self::MAX_QUESTION]), Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => 'text/plain']);
        }
        try {
            $reading = $this->reader->read($text);
        } catch (NoSnapshot $e) {
            $this->logger->error('A question cannot be read: {error}', ['error' => $e->getMessage()]);

            return new Response($this->translator->trans('The server has no data snapshot yet, so no figure can be proven.'), Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain']);
        }
        $page = $reading->page;
        if (null === $page) {
            $this->logger->info('A question read without a model named no page: {problem}', ['problem' => $reading->problem]);

            return new Response(trim($this->translator->trans((string) $reading->problem, ['%name%' => (string) $reading->name]).' '.([] === $reading->know ? '' : $this->translator->trans('The data has: %list%.', ['%list%' => implode('; ', $reading->know)]))), Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => 'text/plain']);
        }
        $layout = json_encode($this->links->layout($page), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return [
            'id' => null,
            'question' => $text,
            'language' => null,
            'origin' => $this->translator->trans('Answered without a model: matched %words%.', ['%words%' => implode(', ', $reading->matched)]),
            // Said in full view above the answer: the page is about the rest of the question.
            'left_out' => [] === $reading->unused ? null : $this->translator->trans('Not understood, and left out: %words%.', ['%words%' => implode(', ', $reading->unused)]),
            'replay' => false,
            'model' => false,
            'refine' => ['indicators' => $reading->indicators, 'months' => $reading->months, 'places' => $reading->places],
            'parts' => fn (): \Generator => $this->stream->whole($page, $layout, heading: 3),
        ];
    }

    /**
     * @param array{indicators: list<array{label: string, ask: string}>, months: list<array{label: string, ask: string, current: bool}>, places: list<array{label: string, ask: string, current: bool}>}|null $refine a question read without a model: the same one for another indicator or month
     *
     * @param ?string $asked the question a model answered: its words are read too, to say when they fit another figure than the one the model chose
     *
     * @return array{proven: int, refused: int, hidden: int, summary: string, link: ?string, actions: string, credits: string} the tally and its sentence, the link to the whole page, and what its sources ask to show (HTML)
     */
    private function ended(PagePart $end, ?array $refine = null, ?string $asked = null): array
    {
        $tally = ['proven' => 0, 'refused' => 0, 'hidden' => 0];
        $link = null;
        $actions = '';
        $credits = '';
        if (null !== $end->page) {
            $view = $this->pages->view($end->page);
            $this->log->shown('ask', $view);
            $tally = $view->tally();
            $link = $this->links->url($end->page);
            // Under the answer: copy it with its sources, its link, and the same layout to tamper with.
            $actions = $this->renderView('_actions.html.twig', ['page' => $view, 'link' => $link, 'attack' => $this->generateUrl('lie', ['page' => basename($link)]), 'refine' => $refine ?? (null === $asked ? null : $this->next($view)), 'also' => null === $asked ? [] : $this->also($asked, $view), 'asked' => $asked]);
            // The credits come last: they depend on every figure of the page.
            $credits = $this->pages->renderCredits($view);
        }
        if (null !== $end->page && 0 === array_sum($tally)) {
            return [...$tally, 'summary' => $this->translator->trans('Finished. This answer shows no figure.'), 'link' => $link, 'actions' => $actions, 'credits' => $credits];
        }
        $summary = $this->translator->trans('Figures proven: %proven%. Figures refused: %refused%. Texts or periods not shown: %hidden%.', ['%proven%' => $tally['proven'], '%refused%' => $tally['refused'], '%hidden%' => $tally['hidden']]);

        return [...$tally, 'summary' => $summary, 'link' => $link, 'actions' => $actions, 'credits' => $credits];
    }

    /** What the page says when there is no page: never the details of a provider error. */
    private function problem(\Throwable $e): string
    {
        return match (true) {
            $e instanceof NoPageInStream => $this->translator->trans('The model wrote no page.'),
            $e instanceof NoSnapshot => $this->translator->trans('The server has no data snapshot yet, so no figure can be proven.'),
            default => $this->failed($e),
        };
    }

    private function failed(\Throwable $e): string
    {
        $this->logger->error('Ask failed: {class}: {error}', ['class' => $e::class, 'error' => $e->getMessage()]);

        return $this->translator->trans('The model could not answer. The details are in the application log.');
    }
}
