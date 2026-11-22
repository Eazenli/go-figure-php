<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The language of the interface: English by default, French if the visitor chose it (a cookie).
 * Only the site follows it: MCP answers stay in English, even for a client that sends the cookie
 * (the MCP Inspector runs on the same host), and so does the text sent to the model.
 */
final class Language
{
    public const string COOKIE = 'lang';
    public const array LANGUAGES = ['en', 'fr'];

    /** Before Symfony's LocaleListener (16) and the translator's LocaleAwareListener (15). */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (str_starts_with($request->getPathInfo(), '/mcp')) {
            return;
        }
        $chosen = $request->cookies->get(self::COOKIE);
        if (\in_array($chosen, self::LANGUAGES, true)) {
            $request->setLocale($chosen);
        }
    }
}
