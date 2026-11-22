<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AiSettings;
use App\Catalog\Catalog;
use App\Catalog\CatalogEntry;
use App\Catalog\Granularity;
use App\Catalog\Places;
use App\Catalog\Theme;
use App\Http\SameOrigin;
use App\Import\CityWeather;
use App\Import\DatasetNotAllowed;
use App\Import\DatasetNotByPlace;
use App\Import\DatasetNotFound;
use App\Import\DatasetStructure;
use App\Import\EurostatApi;
use App\Import\EurostatUnavailable;
use App\Import\ImportRefused;
use App\Import\Importer;
use App\Import\ImportStore;
use App\Import\Proposal;
use App\Import\ProposalAgent;
use App\Import\ProposalType;
use App\Import\WorldBankApi;
use App\Import\WorldBankImporter;
use App\Import\WorldBankUnavailable;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The extended mode: add a Eurostat dataset to the catalog of this machine.
 *
 * Without a key, the visitor looks for a dataset by its words (Eurostat's table of contents, no
 * model), chooses one code per dimension, and imports it. With a key, an agent proposes the whole
 * entry from what the visitor looks for; the visitor reviews it before anything is imported. Either
 * way the server checks every code and reads every value from Eurostat itself.
 */
#[WithMonologChannel('go_figure')]
final class ImportController extends AbstractController
{
    public const int MAX_QUERY = 120;

    public function __construct(
        private readonly EurostatApi $eurostat,
        private readonly Importer $importer,
        private readonly ImportStore $store,
        private readonly Catalog $catalog,
        private readonly ProposalAgent $agent,
        private readonly CityWeather $weather,
        private readonly WorldBankApi $worldBank,
        private readonly WorldBankImporter $worldBankImporter,
        private readonly Places $places,
        private readonly AiSettings $settings,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.import_visitor')]
        private readonly RateLimiterFactoryInterface $importVisitorLimiter,
        #[Autowire(service: 'limiter.import_all')]
        private readonly RateLimiterFactoryInterface $importAllLimiter,
        #[Autowire(service: 'limiter.eurostat_read')]
        private readonly RateLimiterFactoryInterface $eurostatReadLimiter,
    ) {
    }

    #[Route('/import', name: 'import', methods: ['GET'])]
    public function page(Request $request): Response
    {
        $view = [];
        $query = mb_substr(trim($request->query->getString('q')), 0, self::MAX_QUERY);
        $code = trim($request->query->getString('code'));
        try {
            // Reading a dataset downloads from Eurostat: limited per visitor, like an import.
            if (('' !== $code || '' !== $query || '' !== trim($request->query->getString('wb'))) && !$this->eurostatReadLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $view['problems'] = [$this->translator->trans('Too many searches: try again in a few minutes.')];
            } elseif ('' !== $code) {
                $structure = $this->eurostat->structure($code);
                $view['structure'] = $structure;
                $view['form'] = $this->form($structure, self::defaults($structure));
            } elseif ('' !== $query) {
                $view['results'] = $this->eurostat->search($query);
            }
        } catch (DatasetNotFound $e) {
            // The code as typed is echoed only if it is a code: never free text on the site.
            $view['problems'] = [1 === preg_match(EurostatApi::CODE, $e->dataset)
                ? $this->translator->trans('Eurostat has no dataset "%code%".', ['%code%' => $e->dataset])
                : $this->translator->trans('This is not a Eurostat dataset code, such as tour_occ_arnat.')];
        } catch (DatasetNotAllowed) {
            $view['problems'] = [$this->translator->trans('Go Figure does not add this dataset: it takes the economy, businesses, agriculture, energy, the environment, transport, tourism, prices and culture, and no subject about people\'s lives (health, migration, justice, living conditions).')];
        } catch (DatasetNotByPlace $e) {
            $view['problems'] = [$this->translator->trans('The dataset "%code%" is not given by place and period (by currency, for instance): a page of Go Figure cannot draw it.', ['%code%' => $e->dataset])];
        } catch (EurostatUnavailable $e) {
            $this->logger->warning('Eurostat did not answer: {error}', ['error' => $e->getMessage()]);
            $view['problems'] = [$this->translator->trans('Eurostat does not answer right now: try again in a moment.')];
        }

        // The World Bank's indicators, by the words of their name: the visitor then adds one.
        $words = mb_substr(trim($request->query->getString('wb')), 0, self::MAX_QUERY);
        if ('' !== $words && !isset($view['problems'])) {
            try {
                $view['wb_results'] = $this->worldBank->search($words, 12);
            } catch (WorldBankUnavailable $e) {
                $this->logger->warning('The World Bank did not answer: {error}', ['error' => $e->getMessage()]);
                $view['problems'] = [$this->translator->trans('The World Bank does not answer right now: try again in a moment.')];
            }
        }
        $view['wb_query'] = $words;

        $added = $this->catalog->get($request->query->getString('added'));
        $city = $request->query->getString('city');
        // Only a city added here, never one that was typed into the address.
        $view['city'] = \in_array($city, array_column($this->store->cities(), 0), true) ? $city : null;
        $view['city_off_centre'] = null !== $view['city'] && $this->weather->offCentre($city);

        return $this->show($view + ['query' => $query, 'added' => null !== $added && ImportStore::owns($added->id) ? $added : null]);
    }

    #[Route('/import', name: 'import_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (null !== $refused = $this->refuse($request)) {
            return $refused;
        }
        $data = $request->request->all('proposal');
        $code = \is_string($data['code'] ?? null) ? $data['code'] : '';
        try {
            $structure = $this->eurostat->structure($code);
            $form = $this->form($structure, null)->submit($data);
            $proposal = ProposalType::proposal(\is_array($form->getData()) ? $form->getData() : []);
            // Every reason at once: the form's own (a code not offered), and the rules of an entry.
            $problems = $this->problems($this->importer->problems($proposal, $structure));
            if ($form->isValid() && [] === $problems) {
                $entry = $this->importer->import($proposal, $structure);
                $this->logger->info('Imported {code} as {id}', ['code' => $code, 'id' => $entry->id]);

                return $this->redirectToRoute('import', ['added' => $entry->id], Response::HTTP_SEE_OTHER);
            }
        } catch (DatasetNotFound|DatasetNotAllowed|DatasetNotByPlace) {
            return $this->show(['problems' => [$this->translator->trans('This dataset cannot be added.')]], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EurostatUnavailable $e) {
            $this->logger->warning('Eurostat did not answer: {error}', ['error' => $e->getMessage()]);

            return $this->show(['problems' => [$this->translator->trans('Eurostat does not answer right now: try again in a moment.')]], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (ImportRefused $e) {
            $problems = $this->problems($e->reasons);
        } catch (\Throwable $e) {
            // Logged with its details; the visitor gets no internal message.
            $this->logger->error('The import of {code} failed: {error}', ['code' => $code, 'error' => $e->getMessage(), 'exception' => $e]);

            return $this->show(['problems' => [$this->translator->trans('The import failed: nothing was kept. Try again in a moment.')]], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->show(['structure' => $structure, 'form' => $form, 'problems' => $problems], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/import/agent', name: 'import_agent', methods: ['POST'])]
    public function agent(Request $request): Response
    {
        if (null !== $refused = $this->refuse($request)) {
            return $refused;
        }
        if (!$this->settings->hasKey()) {
            return $this->show(['problems' => [$this->translator->trans('The agent needs a key of your own: set AI_PROVIDER, AI_MODEL and AI_API_KEY in .env.local. Without one, look for a dataset by its words.')]], Response::HTTP_CONFLICT);
        }
        $wish = trim((string) preg_replace('/\s+/u', ' ', $request->request->getString('wish')));
        if ('' === $wish || mb_strlen($wish) > ProposalAgent::MAX_REQUEST) {
            return $this->show(['problems' => [$this->translator->trans('Describe what you look for in at most %max% characters.', ['%max%' => ProposalAgent::MAX_REQUEST])], 'wish' => $wish], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $proposal = $this->agent->propose($this->settings, $wish);
            // The agent may choose an indicator of the World Bank: the visitor finds it in the list
            // of the World Bank, where the server writes its title and unit itself.
            if (1 === preg_match(WorldBankApi::INDICATOR, $proposal->code)) {
                return $this->redirectToRoute('import', ['wb' => $proposal->code], Response::HTTP_SEE_OTHER);
            }
            $structure = $this->eurostat->structure($proposal->code);
        } catch (DatasetNotFound|DatasetNotAllowed|DatasetNotByPlace $e) {
            return $this->show(['problems' => [$this->translator->trans('The agent proposed "%code%", which Go Figure cannot add: try other words.', ['%code%' => 1 === preg_match(EurostatApi::CODE, $e->dataset) ? $e->dataset : '?'])], 'wish' => $wish], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('The import agent failed: {error}', ['error' => $e->getMessage()]);

            return $this->show(['problems' => [$this->translator->trans('The agent could not propose a dataset: try again, or look for it by its words.')], 'wish' => $wish], Response::HTTP_BAD_GATEWAY);
        }
        $this->logger->info('The import agent proposed {code}', ['code' => $proposal->code, 'model' => $this->settings->label()]);

        return $this->show([
            'structure' => $structure,
            'form' => $this->form($structure, ProposalType::data($proposal, $structure)),
            'proposed_by' => $this->settings->label(),
            'wish' => $wish,
        ]);
    }

    /**
     * "Add a city": its weather, read from Open-Meteo once and computed here.
     */
    #[Route('/import/city', name: 'import_city', methods: ['POST'])]
    public function city(Request $request): Response
    {
        if (null !== $refused = $this->refuse($request)) {
            return $refused;
        }
        $code = $request->request->getString('city');
        try {
            $this->weather->add($code);
            $this->logger->info('Added the weather of {city}', ['city' => $code]);

            return $this->redirectToRoute('import', ['city' => $code], Response::HTTP_SEE_OTHER);
        } catch (ImportRefused $e) {
            return $this->show(['problems' => $this->problems($e->reasons)], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EurostatUnavailable $e) {
            $this->logger->warning('Open-Meteo did not answer: {error}', ['error' => $e->getMessage()]);

            return $this->show(['problems' => [$this->translator->trans('Open-Meteo does not answer right now: try again in a moment.')]], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            $this->logger->error('Adding the weather of {city} failed: {error}', ['city' => $code, 'error' => $e->getMessage(), 'exception' => $e]);

            return $this->show(['problems' => [$this->translator->trans('The import failed: nothing was kept. Try again in a moment.')]], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * "Add a World Bank indicator": every value by country and year, read from the World Bank; the
     * title and the unit are the World Bank's own words.
     */
    #[Route('/import/world-bank', name: 'import_world_bank', methods: ['POST'])]
    public function worldBank(Request $request): Response
    {
        if (null !== $refused = $this->refuse($request)) {
            return $refused;
        }
        $indicator = $request->request->getString('indicator');
        try {
            $entry = $this->worldBankImporter->import($indicator);
            $this->logger->info('Added the World Bank indicator {indicator}', ['indicator' => $indicator]);

            return $this->redirectToRoute('import', ['added' => $entry->id], Response::HTTP_SEE_OTHER);
        } catch (ImportRefused $e) {
            return $this->show(['problems' => $this->problems($e->reasons)], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (DatasetNotFound) {
            return $this->show(['problems' => [$this->translator->trans('The World Bank has no such indicator among its World Development Indicators.')]], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (DatasetNotAllowed) {
            return $this->show(['problems' => [$this->translator->trans('Go Figure does not add this dataset: it takes the economy, businesses, agriculture, energy, the environment, transport, tourism, prices and culture, and no subject about people\'s lives (health, migration, justice, living conditions).')]], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (WorldBankUnavailable $e) {
            $this->logger->warning('The World Bank did not answer: {error}', ['error' => $e->getMessage()]);

            return $this->show(['problems' => [$this->translator->trans('The World Bank does not answer right now: try again in a moment.')]], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            $this->logger->error('Adding the World Bank indicator failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);

            return $this->show(['problems' => [$this->translator->trans('The import failed: nothing was kept. Try again in a moment.')]], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * A post from elsewhere, or one too many: refused before anything is downloaded or asked.
     */
    private function refuse(Request $request): ?Response
    {
        if (!SameOrigin::allows($request)) {
            return new Response($this->translator->trans('Datasets are added from the page itself.'), Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/plain']);
        }
        foreach ([$this->importVisitorLimiter->create($request->getClientIp() ?? 'unknown'), $this->importAllLimiter->create('all')] as $limiter) {
            $limit = $limiter->consume();
            if (!$limit->isAccepted()) {
                return new Response($this->translator->trans('Too many imports: try again after %time%.', ['%time%' => $limit->getRetryAfter()->format('H:i T')]), Response::HTTP_TOO_MANY_REQUESTS, ['Content-Type' => 'text/plain']);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $data
     *
     * @return FormInterface<array<string, mixed>|null>
     */
    private function form(DatasetStructure $structure, ?array $data): FormInterface
    {
        return $this->forms->createNamed('proposal', ProposalType::class, $data, [
            'structure' => $structure,
            'action' => $this->generateUrl('import_save'),
        ]);
    }

    /**
     * The words a visitor starts from: Eurostat's title, if it holds no number, and a total.
     *
     * @return array<string, mixed>
     */
    private static function defaults(DatasetStructure $structure): array
    {
        $title = 1 === preg_match('/\d/', $structure->label) ? '' : $structure->label;

        return ProposalType::data(new Proposal(code: $structure->code, title: $title, theme: Theme::Crowds), $structure);
    }

    /**
     * @param list<array{string, array<string, string>}> $reasons
     *
     * @return list<string>
     */
    private function problems(array $reasons): array
    {
        return array_map(fn (array $r): string => $this->translator->trans($r[0], $r[1]), $reasons);
    }

    /**
     * @param array<string, mixed> $view
     */
    private function show(array $view, int $status = Response::HTTP_OK): Response
    {
        $imported = array_values(array_filter($this->catalog->all(), static fn (CatalogEntry $e): bool => ImportStore::owns($e->id)));

        return $this->render('import.html.twig', $view + [
            'query' => '',
            'added' => null,
            'city' => null,
            // The cities that can still be added, by name in the language of the page.
            'cities' => $this->cities(),
            'cities_full' => $this->weather->full(),
            'added_cities' => array_column($this->store->cities(), 0),
            // A shipped city to compare an added one with: /compare needs two places.
            'other_city' => array_values(array_filter(array_merge(...array_values(array_map(static fn (CatalogEntry $e): array => $e->geo, $this->catalog->shipped()))), Places::isCity(...)))[0] ?? null,
            'imported' => $imported,
            'max' => ImportStore::MAX_ENTRIES,
            'live' => $this->settings->hasKey(),
            'model' => $this->settings->label(),
            'compose' => array_map(self::compose(...), array_combine(array_map(static fn (CatalogEntry $e): string => $e->id, $imported), $imported)),
        ], new Response(status: $status));
    }

    /**
     * @return array<string, string> code => "Malaga, Spain"
     */
    private function cities(): array
    {
        $cities = [];
        foreach ($this->weather->addable() as $code => $city) {
            $cities[$code] = \sprintf('%s, %s', $this->translator->trans($city['name'], domain: 'places'), $this->places->name($city['country']));
        }
        (new \Collator($this->translator->getLocale()))->asort($cities);

        return $cities;
    }

    /**
     * The query of /compose that draws an imported dataset at once: a few places, its latest years.
     *
     * @return array<string, mixed>
     */
    private static function compose(CatalogEntry $entry): array
    {
        $places = array_values(array_intersect(['FR', 'ES', 'IT', 'PT', 'EL', 'DE'], $entry->geo)) ?: \array_slice($entry->geo, 0, 3);
        $periods = $entry->time->periods();
        $last = Granularity::Monthly === $entry->time->granularity ? 12 : 10;

        return ['dataset' => $entry->id, 'places' => \array_slice($places, 0, 3), 'from' => $periods[max(0, \count($periods) - $last)] ?? $entry->time->from, 'to' => $entry->time->to];
    }
}
