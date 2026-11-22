<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Language;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class LanguageController
{
    /**
     * The FR / EN switch of the header: remembers the choice (App\Http\Language reads it), then goes
     * back to the page it was clicked on. Only a path of this site: never a redirect elsewhere.
     */
    #[Route('/language/{language}', name: 'language', requirements: ['language' => 'en|fr'], methods: ['GET'])]
    public function choose(string $language, Request $request): RedirectResponse
    {
        $back = $request->query->getString('back', '/');
        if (1 !== preg_match('#^/(?![/\\\\])[\x21-\x7E]*$#D', $back)) {
            $back = '/';
        }
        $response = new RedirectResponse($back);
        $response->headers->setCookie(Cookie::create(Language::COOKIE, $language, new \DateTimeImmutable('+1 year'), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));

        return $response;
    }
}
