<?php

declare(strict_types=1);

namespace App\Import;

/**
 * A proposal the server does not import, with every reason: English message templates, translated
 * where they are shown.
 */
final class ImportRefused extends \RuntimeException
{
    /**
     * @param list<array{string, array<string, string>}> $reasons message template and its parameters
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', array_map(static fn (array $r): string => strtr($r[0], $r[1]), $reasons)));
    }
}
