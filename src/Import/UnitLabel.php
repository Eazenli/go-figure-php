<?php

declare(strict_types=1);

namespace App\Import;

use App\Catalog\CatalogLoader;

/**
 * The label shown after every figure of an imported dataset: what the visitor (or the model) wrote,
 * which may hold no number, and the scale the unit code carries, written by the server ("THS_PAS"
 * and "passengers" make "thousand passengers"; "P_THAB" and "nights" make "nights per thousand
 * inhabitants"; "EUR_HAB" and "euro" make "euro per inhabitant"). A scale is never left to free text.
 */
final class UnitLabel
{
    public static function of(string $code, string $label): string
    {
        $label = trim($label);
        // Per inhabitant without a scale (EUR_HAB, KG_HAB): a figure per head, not a total.
        if (\in_array('HAB', explode('_', $code), true)) {
            $label .= ' per inhabitant';
        }
        foreach (CatalogLoader::SCALES as $pattern => $word) {
            foreach (explode('_', $code) as $part) {
                if (1 !== preg_match($pattern, $part) || 1 === preg_match('/\b'.$word.'\b/i', $label)) {
                    continue;
                }
                // Per inhabitants (THAB, HTHAB, MHAB): the scale goes after; otherwise before.
                $label = str_ends_with($part, 'HAB') ? $label.' per '.$word.' inhabitants' : $word.' '.$label;
            }
        }

        return $label;
    }
}
