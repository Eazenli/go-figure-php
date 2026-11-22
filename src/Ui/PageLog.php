<?php

declare(strict_types=1);

namespace App\Ui;

use App\Proof\ComputedProof;
use App\Proof\MeasuredProof;
use App\Proof\Refusal;
use App\Ui\View\PageView;
use App\Ui\View\Words;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

/**
 * The trail of a page that was shown: each figure the server refused, with the cell asked for and
 * why, and each text or period it would not show, with why. Where to look when a page shows a
 * refusal card, or when someone tries to make it lie.
 *
 * The page no longer holds a text it refused (so that no stray number can reach it). What the
 * model wrote is in the MCP SDK's own log of the call ("Executing tool", debug level).
 */
#[WithMonologChannel('go_figure')]
final readonly class PageLog
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $source where the page comes from: "render_page", "compose" or "lie"
     */
    public function shown(string $source, PageView $page): void
    {
        foreach ($page->parts() as $part) {
            if ($part instanceof Refusal) {
                $this->logger->info('{source} refused a figure: {reason}', ['source' => $source, 'reason' => $part->reason->value, 'requested' => $part->requested]);
            } elseif (!$part instanceof MeasuredProof && !$part instanceof ComputedProof && null !== $part->problem) {
                $this->logger->info('{source} did not show a {part}: {problem}', [
                    'source' => $source,
                    'part' => $part instanceof Words ? 'text' : 'period',
                    'problem' => $part->problem,
                ]);
            }
        }

        $this->logger->info('{source} showed a page (proven figures: {proven}, refused: {refused}, texts or periods not shown: {hidden})', ['source' => $source, ...$page->tally()]);
    }
}
