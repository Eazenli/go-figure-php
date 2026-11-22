<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\SecurityHeaders;
use App\Tests\Support\FactsFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityHeadersTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'home' => ['/'];
        yield 'composer with a page' => ['/compose?dataset=nights_total&places[]=FR&from=2019&to=2024'];
        yield 'refused proof' => ['/proof/not-a-proof'];
    }

    #[DataProvider('pages')]
    public function testEveryPageCarriesTheSecurityHeaders(string $url): void
    {
        $client = self::createClient();
        $path = self::getContainer()->getParameter('app.facts_path');
        FactsFixture::write($path);

        $client->request('GET', $url);

        foreach (SecurityHeaders::HEADERS as $name => $value) {
            self::assertResponseHeaderSame($name, $value);
        }
    }

    /**
     * Scripts only add to a page (the proof panel): each one is a file of the site, none inline, and
     * nothing comes from elsewhere. The functional tests run without JavaScript.
     */
    public function testThePagesLoadNoInlineScriptAndNothingFromElsewhere(): void
    {
        $client = self::createClient();
        $path = self::getContainer()->getParameter('app.facts_path');
        FactsFixture::write($path);

        foreach (['/', '/compose?dataset=nights_total&places[]=FR&from=2019&to=2024', '/', '/lie'] as $url) {
            $crawler = $client->request('GET', $url);
            foreach ($crawler->filter('script') as $script) {
                self::assertInstanceOf(\DOMElement::class, $script);
                self::assertMatchesRegularExpression('#^/[a-z-]+\.js$#', $script->getAttribute('src'), $url);
                self::assertSame('', trim($script->textContent), $url);
            }
            self::assertCount(0, $crawler->filter('link[href^="http"], img[src^="http"], script[src^="http"]'), $url);
        }
    }
}
