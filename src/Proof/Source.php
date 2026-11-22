<?php

declare(strict_types=1);

namespace App\Proof;

/**
 * Where a dataset comes from: the part of a proof that the catalog provides.
 */
final readonly class Source
{
    public function __construct(
        /** e.g. "eurostat" or "worldbank_cckp". */
        public string $provider,
        /** The page that "Open on source" links to. */
        public string $url,
    ) {
    }
}
