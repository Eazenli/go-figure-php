<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * JSON-RPC over the MCP HTTP endpoint, as a host sends it.
 */
trait McpRequests
{
    /**
     * Opens a session and returns its id.
     *
     * @param array<string, mixed> $capabilities what the host declares, e.g. the MCP Apps extension
     */
    private function initialize(KernelBrowser $client, array $capabilities = []): string
    {
        $init = $this->rpc($client, null, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) $capabilities,
            'clientInfo' => ['name' => 'go-figure-tests', 'version' => '1'],
        ]);
        self::assertSame('go-figure', $init['result']['serverInfo']['name']);
        $session = $client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertIsString($session);
        $this->rpc($client, $session, 'notifications/initialized');

        return $session;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(KernelBrowser $client, ?string $session, string $method, array $params = []): array
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method];
        if (!str_starts_with($method, 'notifications/')) {
            $message['id'] = random_int(1, \PHP_INT_MAX);
        }
        if ([] !== $params) {
            $message['params'] = $params;
        }

        $client->request('POST', '/mcp/go-figure', server: array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_SESSION_ID' => $session,
            'HTTP_MCP_PROTOCOL_VERSION' => null === $session ? null : '2025-06-18',
        ]), content: json_encode($message, \JSON_THROW_ON_ERROR));

        self::assertLessThan(300, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        return json_decode((string) $client->getResponse()->getContent() ?: '[]', true, flags: \JSON_THROW_ON_ERROR);
    }
}
