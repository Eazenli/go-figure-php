<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Tests\Support\FactsFixture;
use App\Ui\PageLink;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /p/{token}: the whole page from a link, built again and proven again on every visit.
 */
final class PageLinkTest extends WebTestCase
{
    private const array LAYOUT = ['title' => 'Nights in France', 'components' => [
        ['kind' => 'kpi', 'label' => 'Nights in France', 'figure' => ['type' => 'measured', 'dataset' => 'nights_total', 'geo' => 'FR', 'time' => '2024']],
    ]];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        FactsFixture::write($this->client->getContainer()->getParameter('app.facts_path'));
    }

    public function testALinkRebuildsThePageAndProvesEveryFigureAgain(): void
    {
        $this->client->request('GET', '/p/'.PageLink::encode(self::LAYOUT));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('title', 'Nights in France · Go Figure');
        self::assertSelectorTextSame('.gf-kpi .gf-figure', '457,644,262');
        self::assertSelectorTextContains('.tally', 'Figures proven: 1.');
    }

    public function testALinkWithAValueWrittenInIsRefusedLikeAnyLayout(): void
    {
        $layout = self::LAYOUT;
        $layout['components'][0]['figure']['value'] = 999000000;

        $this->client->request('GET', '/p/'.PageLink::encode($layout));

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextSame('.problem', 'This link does not hold a page.');
    }

    public function testALinkThatHoldsNoPageIsNotFound(): void
    {
        $bomb = rtrim(strtr(base64_encode((string) gzdeflate(str_repeat(' ', 1_000_000), 9)), '+/', '-_'), '=');

        foreach (['abc', $bomb, PageLink::encode(['title' => 'No components'])] as $token) {
            $this->client->request('GET', '/p/'.$token);
            self::assertResponseStatusCodeSame(404, $token);
        }
        self::assertNull(PageLink::decode($bomb));
    }

    public function testASmallLinkCannotNameThousandsOfCells(): void
    {
        $tile = self::LAYOUT['components'][0];
        $this->client->request('GET', '/p/'.PageLink::encode(['title' => 'Nights', 'components' => array_fill(0, 30, $tile)]));

        self::assertResponseIsSuccessful();
        self::assertCount(20, $this->client->getCrawler()->filter('.gf-kpi'));
    }

    public function testATextOnlyMcpHostGetsTheLinkToTheWholePage(): void
    {
        $page = $this->client->getContainer()->get(PageLink::class)->url(
            $this->client->getContainer()->get('serializer')->denormalize(self::LAYOUT, \App\Layout\Page::class),
        );

        self::assertStringStartsWith('http://localhost/p/', $page);
        self::assertSame(self::LAYOUT, PageLink::decode(substr($page, \strlen('http://localhost/p/'))));
    }
}
