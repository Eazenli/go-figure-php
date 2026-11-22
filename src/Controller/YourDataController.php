<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Catalog;
use App\Catalog\CatalogEntry;
use App\Http\SameOrigin;
use App\Import\FileImporter;
use App\Import\ImportRefused;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Your own data": a CSV file is deposited, the server says what it read in it, the depositor
 * names its columns of numbers, and each becomes a dataset the page can question, with the same
 * proofs. Two steps, and nothing kept between them: the second one carries the file again.
 */
#[WithMonologChannel('go_figure')]
final class YourDataController extends AbstractController
{
    public function __construct(
        private readonly FileImporter $files,
        private readonly Catalog $catalog,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.import_visitor')]
        private readonly RateLimiterFactoryInterface $importVisitorLimiter,
        #[Autowire(service: 'limiter.import_all')]
        private readonly RateLimiterFactoryInterface $importAllLimiter,
    ) {
    }

    #[Route('/data', name: 'your_data', methods: ['GET', 'POST'])]
    public function data(Request $request): Response
    {
        $view = ['file' => null, 'csv' => '', 'name' => '', 'problems' => [], 'kept' => [], 'named' => [], 'max_kb' => FileImporter::MAX_BYTES / 1024, 'max_rows' => FileImporter::MAX_ROWS, 'max_places' => FileImporter::MAX_PLACES, 'max_columns' => FileImporter::MAX_COLUMNS];
        if (!$request->isMethod('POST')) {
            return $this->render('data.html.twig', $view + ['mine' => $this->mine()]);
        }
        // Another site could deposit a file here in the visitor's name.
        if (!SameOrigin::allows($request)) {
            throw new AccessDeniedHttpException('A file is deposited from this page.');
        }
        $upload = $request->files->get('file');
        if ($upload instanceof UploadedFile) {
            $view['name'] = $upload->getClientOriginalName();
            $view['csv'] = $upload->isValid() && $upload->getSize() <= FileImporter::MAX_BYTES ? (string) file_get_contents($upload->getPathname()) : '';
            if ('' === $view['csv']) {
                $view['problems'] = [$this->translator->trans('This file is larger than %kb% KB.', ['%kb%' => (string) (FileImporter::MAX_BYTES / 1024)])];
            }
        } else {
            $view['name'] = mb_substr($request->request->getString('name'), 0, 120);
            $view['csv'] = $request->request->getString('csv');
        }
        $status = Response::HTTP_OK;
        try {
            if ([] === $view['problems']) {
                $view['file'] = $this->files->read($view['csv']);
            }
            if (null !== $view['file'] && $request->request->has('keep')) {
                $view['named'] = array_map(static fn (mixed $column): array => ['title' => \is_array($column) && \is_string($column['title'] ?? null) ? $column['title'] : '', 'unit' => \is_array($column) && \is_string($column['unit'] ?? null) ? $column['unit'] : ''], $request->request->all('columns'));
                foreach ([$this->importVisitorLimiter->create($request->getClientIp() ?? 'unknown'), $this->importAllLimiter->create('all')] as $limiter) {
                    if (!$limiter->consume()->isAccepted()) {
                        throw new ImportRefused([['Too many imports for now: try again later.', []]]);
                    }
                }
                $view['kept'] = $this->files->import($view['csv'], $view['name'], $view['named']);
                $this->logger->info('A file was deposited: {datasets} dataset(s)', ['datasets' => \count($view['kept'])]);
                $view['file'] = null;
            }
        } catch (ImportRefused $e) {
            $view['problems'] = array_map(fn (array $reason): string => $this->translator->trans($reason[0], $reason[1]), $e->reasons);
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('data.html.twig', $view + ['mine' => $this->mine()], new Response(status: $status));
    }

    /**
     * The datasets that came from a file on this machine.
     *
     * @return list<CatalogEntry>
     */
    private function mine(): array
    {
        return array_values(array_filter($this->catalog->all(), static fn (CatalogEntry $entry): bool => FileImporter::PROVIDER === $entry->provider));
    }
}
