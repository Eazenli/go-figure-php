<?php

declare(strict_types=1);

namespace App\Home;

use App\Catalog\Catalog;
use App\Data\NoSnapshot;
use App\Layout\Measured;
use App\Layout\Page;
use App\Proof\MeasuredProof;
use App\Proof\Resolver;
use App\Ui\PageLink;
use App\Ui\PageRenderer;
use App\Ui\View\PageView;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * What the one page of the site shows under the question: how a figure is made, on a real cell,
 * and one page as a model would send it. Nothing of it before the data snapshot exists.
 */
#[WithMonologChannel('go_figure')]
final readonly class HomeContent
{
    public function __construct(
        private Catalog $catalog,
        private PageRenderer $pages,
        private DenormalizerInterface $denormalizer,
        private LoggerInterface $logger,
        private Resolver $resolver,
        /** A layout as a model would send it (demo/home.json): the example the page shows. */
        #[Autowire('%app.home_layout%')]
        private string $example,
    ) {
    }

    /**
     * @return array{sources: array<string, string>, example: ?PageView, example_token: ?string, showcase: ?array{reference: string, proof: MeasuredProof}, demo: ?MeasuredProof}
     */
    public function view(string $locale): array
    {
        // The sources the catalog reads, with their licence: each figure's proof names its own.
        $sources = [];
        foreach ($this->catalog->all() as $entry) {
            $sources[$entry->provider] = $entry->licence;
        }
        $example = $this->example($locale);

        return [
            'sources' => $sources,
            'example' => $example,
            // "Try to make it lie" under the example: its layout, as the address of a page holds one.
            'example_token' => null === $example ? null : $this->exampleToken($locale),
            // How a page is made, shown on a real cell: none before the snapshot exists.
            'showcase' => null === $example ? null : $this->showcase(),
            // The first screen: a claim a chat model could make, and the cell that settles it (a
            // cold city in winter, when the catalog has it).
            'demo' => null === $example ? null : $this->demo(),
        ];
    }

    /**
     * The first cell the example names, as the model wrote it, and its proof.
     *
     * @return array{reference: string, proof: MeasuredProof}|null
     */
    private function showcase(): ?array
    {
        try {
            $layout = json_decode((string) file_get_contents($this->example), true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $cell = self::firstCell($layout);
        if (null === $cell) {
            return null;
        }
        $proof = $this->resolver->measured(new Measured('measured', $cell['dataset'], $cell['geo'], $cell['time']));

        return $proof instanceof MeasuredProof ? ['reference' => json_encode($cell, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), 'proof' => $proof] : null;
    }

    private function demo(): ?MeasuredProof
    {
        try {
            $proof = $this->resolver->measured(new Measured('measured', 'temp_max_city', 'DK001C', 'M01'));
        } catch (\Throwable) {
            return null;
        }

        return $proof instanceof MeasuredProof ? $proof : null;
    }

    /**
     * @return array{type: string, dataset: string, geo: string, time: string}|null
     */
    private static function firstCell(mixed $node): ?array
    {
        if (!\is_array($node)) {
            return null;
        }
        if ('measured' === ($node['type'] ?? null) && \is_string($node['dataset'] ?? null) && \is_string($node['geo'] ?? null) && \is_string($node['time'] ?? null)) {
            return ['type' => 'measured', 'dataset' => $node['dataset'], 'geo' => $node['geo'], 'time' => $node['time']];
        }
        foreach ($node as $child) {
            if (null !== $found = self::firstCell($child)) {
                return $found;
            }
        }

        return null;
    }


    /**
     * The example page, read and proven like any page a model sends: a proven figure, a refused
     * cell and a number the model wrote, side by side. In the page's language when there is a
     * layout for it (demo/home.fr.json), as a model asked in that language would write it. None
     * before the data snapshot exists.
     */
    private function exampleToken(string $language): ?string
    {
        $file = (string) preg_replace('/\.json$/', '.'.$language.'.json', $this->example);
        try {
            $layout = json_decode((string) file_get_contents(is_file($file) ? $file : $this->example), true, 64, \JSON_THROW_ON_ERROR);

            return \is_array($layout) ? PageLink::encode($layout) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function example(string $language): ?PageView
    {
        $file = (string) preg_replace('/\.json$/', '.'.$language.'.json', $this->example);
        try {
            $layout = json_decode((string) file_get_contents(is_file($file) ? $file : $this->example), true, 64, \JSON_THROW_ON_ERROR);

            return $this->pages->view($this->denormalizer->denormalize($layout, Page::class));
        } catch (NoSnapshot) {
            return null;
        } catch (\Throwable $e) {
            // The shipped layout is tested: this is a broken install, not a visitor's mistake.
            $this->logger->error('The example of the home page cannot be shown: {error}', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
