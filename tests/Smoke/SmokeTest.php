<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Tests\Support\McpRequests;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What a visitor and an MCP client hit first.
 */
final class SmokeTest extends WebTestCase
{
    use McpRequests;

    public function testThePageOfMcpShowsItsEndpoint(): void
    {
        $client = self::createClient();
        $client->request('GET', '/connect');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[aria-labelledby="mcp"] code', 'http://localhost/mcp/go-figure');
    }

    public function testTheHealthcheckAnswersOk(): void
    {
        $client = self::createClient();
        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $client->getResponse()->getContent());
    }

    public function testTheMcpServerListsOnlyTheToolsOfGoFigure(): void
    {
        $client = self::createClient();
        $session = $this->initialize($client, ['extensions' => ['io.modelcontextprotocol/ui' => ['mimeTypes' => ['text/html;profile=mcp-app']]]]);

        $tools = $this->rpc($client, $session, 'tools/list')['result']['tools'];
        $names = array_column($tools, 'name');
        sort($names);
        self::assertSame(['add_city', 'add_dataset', 'add_world_bank_indicator', 'describe_dataset', 'describe_eurostat_dataset', 'list_datasets', 'render_page', 'search_eurostat', 'search_world_bank'], $names);

        $page = array_values(array_filter($tools, static fn (array $t) => 'render_page' === $t['name']))[0];
        self::assertSame('ui://go-figure/page', $page['_meta']['ui']['resourceUri']);
    }
}
