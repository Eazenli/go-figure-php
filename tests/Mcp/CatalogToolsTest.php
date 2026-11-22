<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Tests\Support\McpRequests;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The catalog tools and the page prompt, as an MCP host calls them.
 */
final class CatalogToolsTest extends WebTestCase
{
    use McpRequests;

    private KernelBrowser $client;
    private string $session;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->session = $this->initialize($this->client);
    }

    public function testTheToolsAreListedAsReadOnly(): void
    {
        $tools = array_column($this->rpc($this->client, $this->session, 'tools/list')['result']['tools'], null, 'name');

        foreach (['list_datasets', 'describe_dataset'] as $name) {
            self::assertArrayHasKey($name, $tools);
            self::assertTrue($tools[$name]['annotations']['readOnlyHint']);
        }
        self::assertSame(['id'], $tools['describe_dataset']['inputSchema']['required']);
    }

    public function testListDatasetsGivesEveryEntry(): void
    {
        $result = $this->tool('list_datasets');

        self::assertSame(['nights_total', 'temp_max', 'temp_max_city'], array_column($result['structuredContent']['datasets'], 'id'));
        self::assertStringContainsString('- `nights_total`: Nights spent in tourist accommodation (nights; years 2019 to 2025, written like "2025"; 8 places)', $result['content'][0]['text']);
        self::assertStringContainsString('- `temp_max`: Average daily maximum temperature (°C; months of the year, written M01 to M12, averaged over 1991-2020; 2 places)', $result['content'][0]['text']);
    }

    public function testDescribeDatasetGivesWhatTheModelNeedsToNameACell(): void
    {
        $result = $this->tool('describe_dataset', ['id' => 'nights_total']);
        $text = $result['content'][0]['text'];

        self::assertFalse($result['isError'] ?? false);
        self::assertStringContainsString('Scope: Hotels, holiday homes and campsites', $text);
        self::assertStringContainsString('Places (Eurostat codes, e.g. EL for Greece): AT, EL, ES, FR, IT, MT, NL, PT.', $text);
        self::assertStringContainsString('- Not hotels only', $text);
        self::assertStringContainsString('{"type":"measured","dataset":"nights_total","geo":"AT","time":"2025"}', $text);
        self::assertSame(['type' => 'measured', 'dataset' => 'nights_total', 'geo' => 'AT', 'time' => '2025'], $result['structuredContent']['example']);
    }

    public function testACityIsDescribedByItsNameAndAComputedFigureByItsMethod(): void
    {
        $result = $this->tool('describe_dataset', ['id' => 'temp_max_city']);
        $text = $result['content'][0]['text'];

        self::assertStringContainsString('Places (Eurostat codes; a city code such as ES004C starts with the code of its country): ES004C (Seville), PT001C (Lisbon).', $text);
        self::assertStringContainsString('Computed by Go Figure from the source: Go Figure averages the daily maximum temperature', $text);
        self::assertSame(['ES004C' => 'Seville', 'PT001C' => 'Lisbon'], $result['structuredContent']['place_names']);
        self::assertSame('{"type":"measured","dataset":"temp_max_city","geo":"ES004C","time":"M12"}', json_encode($result['structuredContent']['example']));
    }

    public function testAnUnknownDatasetIsAnErrorThatListsTheKnownOnes(): void
    {
        $result = $this->tool('describe_dataset', ['id' => 'nights_hotels']);

        self::assertTrue($result['isError']);
        self::assertSame('There is no dataset "nights_hotels". The datasets are: nights_total, temp_max, temp_max_city.', $result['content'][0]['text']);
    }

    public function testThePromptCarriesTheContractAndTheQuestion(): void
    {
        $prompts = $this->rpc($this->client, $this->session, 'prompts/list')['result']['prompts'];
        self::assertSame(['proven_page'], array_column($prompts, 'name'));
        self::assertSame('question', $prompts[0]['arguments'][0]['name']);
        self::assertStringStartsWith('The question to answer', $prompts[0]['arguments'][0]['description'] ?? '');

        $prompt = $this->rpc($this->client, $this->session, 'prompts/get', ['name' => 'proven_page', 'arguments' => ['question' => 'Is Portugal quieter than Spain?']]);
        $text = $prompt['result']['messages'][0]['content']['text'];

        self::assertSame('user', $prompt['result']['messages'][0]['role']);
        self::assertStringContainsString('Never write a value yourself', $text);
        self::assertStringContainsString('a year or a month goes only in the time, from and to fields', $text);
        self::assertStringEndsWith('Question: Is Portugal quieter than Spain?', $text);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function tool(string $name, array $arguments = []): array
    {
        $response = $this->rpc($this->client, $this->session, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
        self::assertArrayHasKey('result', $response, json_encode($response, \JSON_THROW_ON_ERROR));

        return $response['result'];
    }
}
