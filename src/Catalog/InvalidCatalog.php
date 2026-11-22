<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * The catalog has at least one invalid entry. Nothing is loaded: a broken file must fail the tests,
 * not quietly vanish from the app.
 */
final class InvalidCatalog extends \RuntimeException
{
    /**
     * @param list<string> $errors one line per error, starting with the file name
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct("The catalog is invalid:\n- ".implode("\n- ", $errors));
    }
}
