<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Whether a request that spends something (a key, a download) comes from this site's own page: a
 * site the visitor opens elsewhere could otherwise post to localhost:8080. Fetch Metadata first;
 * browsers without it (or over plain HTTP on a LAN) still send Origin.
 */
final class SameOrigin
{
    public static function allows(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        $origin = $request->headers->get('Origin');

        return null !== $site
            ? \in_array($site, ['same-origin', 'none'], true)
            : null === $origin || $origin === $request->getSchemeAndHttpHost();
    }
}
