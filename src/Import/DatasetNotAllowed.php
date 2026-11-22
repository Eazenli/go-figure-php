<?php

declare(strict_types=1);

namespace App\Import;

/**
 * A Eurostat dataset outside the subjects of Go Figure (travel: tourism, transport, prices,
 * environment, energy): never read, never shown (no sensitive subject).
 */
final class DatasetNotAllowed extends \RuntimeException
{
    public function __construct(public readonly string $dataset)
    {
        parent::__construct(\sprintf('Go Figure does not add "%s": it takes the economy, businesses, agriculture, energy, the environment, transport, tourism, prices and culture, and no subject about people\'s lives (health, migration, justice, living conditions).', $dataset));
    }
}
