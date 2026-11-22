<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Catalog;
use App\Catalog\Places;
use App\Composer\Comparison;
use App\Composer\ComparisonType;
use App\Composer\Composition;
use App\Composer\CompositionType;
use App\Composer\PageComposer;
use App\Data\NoSnapshot;
use App\Ui\PageLog;
use App\Ui\PageRenderer;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The composer: the visitor builds a page from the catalog, with proofs and refusals, and no AI.
 */
#[WithMonologChannel('go_figure')]
final class ComposeController extends AbstractController
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly FormFactoryInterface $forms,
        private readonly PageComposer $composer,
        private readonly PageRenderer $pages,
        private readonly LoggerInterface $logger,
        private readonly PageLog $log,
    ) {
    }

    #[Route('/compose', name: 'compose', methods: ['GET'])]
    public function compose(Request $request): Response
    {
        // Unnamed, so the address reads ?dataset=…&places[]=FR. Symfony submits such a form only
        // when the query holds at least one of its fields: a first visit shows the empty composer.
        $form = $this->forms->createNamed('', CompositionType::class, new Composition())->handleRequest($request);

        $view = null;
        $problem = null;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $view = $this->pages->view($this->composer->page($form->getData()));
                $this->log->shown('compose', $view);
            } catch (NoSnapshot $e) {
                $this->logger->error('The composer cannot prove any figure: {error}', ['error' => $e->getMessage()]);
                $problem = 'The server has no data snapshot yet, so no figure can be proven.';
            }
        }

        // The places each indicator covers: the page lists only those of the indicator chosen (nights
        // are by country, the weather by city), so countries and cities are never offered together
        // for nothing.
        $coverage = [];
        foreach ($this->catalog->all() as $entry) {
            $coverage[$entry->id] = $entry->geo;
        }

        return $this->render('compose.html.twig', ['form' => $form, 'pages' => null === $view ? [] : [$view], 'problem' => $problem, 'compare' => false, 'example' => false, 'coverage' => $coverage]);
    }

    #[Route('/compare', name: 'compare', methods: ['GET'])]
    public function compare(Request $request): Response
    {
        // A first visit shows an example, already chosen and drawn: the visitor sees a page at once.
        // A visitor's own choice, even a wrong one, never shows the example's.
        $chosen = $request->query->has('places') || $request->query->has('month');
        $example = $chosen ? new Comparison() : $this->composer->example();
        $form = $this->forms->createNamed('', ComparisonType::class, $example)->handleRequest($request);
        $showExample = !$form->isSubmitted() && 2 <= \count($example->places);
        $views = [];
        $problem = null;
        if ($form->isSubmitted() ? $form->isValid() : $showExample) {
            try {
                // One page per question of the traveller (prices, crowds, weather, getting there).
                foreach ($this->composer->compare($form->getData()) as $page) {
                    $views[] = $view = $this->pages->view($page);
                    $this->log->shown($showExample ? 'compare-example' : 'compare', $view);
                }
            } catch (NoSnapshot $e) {
                if (!$showExample) {
                    $this->logger->error('The comparison cannot prove any figure: {error}', ['error' => $e->getMessage()]);
                }
                $problem = 'The server has no data snapshot yet, so no figure can be proven.';
            }
        }

        // The cities chosen, by country: where a source publishes by country, the bar of the country
        // says which of the chosen cities it stands for.
        $standIns = [];
        $places = $form->getData()->places;
        foreach ($places as $place) {
            if (Places::isCity($place) && !\in_array(substr($place, 0, 2), $places, true)) {
                $standIns[substr($place, 0, 2)][] = $place;
            }
        }

        return $this->render('compose.html.twig', ['form' => $form, 'pages' => $views, 'problem' => $problem, 'compare' => true, 'example' => $showExample, 'stand_ins' => $standIns]);
    }
}
