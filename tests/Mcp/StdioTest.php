<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * MCP over STDIO, as Claude Desktop runs it (`docker exec -i go-figure php bin/console mcp:server`).
 *
 * Standard output carries the protocol and nothing else: one log line there and the host can no
 * longer parse the stream. So logs go to standard error, never to php://stdout as container
 * images often do. Checked with the dev and prod configurations of the app, in a separate process,
 * while the server logs an error. The CI checks the same with the production image and its php.ini.
 */
final class StdioTest extends TestCase
{
    private const int TIMEOUT = 60;

    /**
     * Each run builds its own containers, in a directory of its own: from the configuration as it
     * stands (a prod container never checks it again), without touching var/cache/ or var/log/,
     * and without getting in the way of another run.
     */
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir().'/go-figure-stdio-'.bin2hex(random_bytes(6));
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(self::$dir);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function environments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'prod' => ['prod'];
    }

    #[DataProvider('environments')]
    public function testStandardOutputCarriesOnlyTheProtocolWhileTheAppLogs(string $env): void
    {
        // A cell the catalog has, whatever entries it holds: the server looks it up in the snapshot.
        $entry = Yaml::parseFile(glob(\dirname(__DIR__, 2).'/catalog/*.yaml')[0] ?? self::fail('The catalog is empty.'));
        $cell = ['type' => 'measured', 'dataset' => $entry['id'], 'geo' => $entry['geo'][0], 'time' => (string) $entry['time']['to']];

        [$stdout, $stderr] = self::serve($env, [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'go-figure-tests', 'version' => '1'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            // The snapshot is missing (FACTS_PATH below): render_page says so and logs an error.
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name' => 'render_page',
                'arguments' => ['layout' => ['title' => 'A page', 'components' => [['kind' => 'kpi', 'label' => 'A figure', 'figure' => $cell]]]],
            ]],
        ]);

        $responses = [];
        foreach (explode("\n", trim($stdout)) as $line) {
            $message = json_decode($line, true);
            self::assertIsArray($message, \sprintf("Standard output holds a line that is not JSON-RPC:\n%s\n\nStandard error:\n%s", $line, $stderr));
            self::assertSame('2.0', $message['jsonrpc'] ?? null, $line);
            if (isset($message['id'])) {
                $responses[$message['id']] = $message;
            }
        }

        self::assertSame('go-figure', $responses[1]['result']['serverInfo']['name'] ?? null, $stdout);
        self::assertTrue($responses[2]['result']['isError'] ?? false, $stdout);
        self::assertStringStartsWith('The server has no data snapshot yet', $responses[2]['result']['content'][0]['text'] ?? '');
        // The error was logged, on standard error.
        self::assertStringContainsString('render_page cannot prove any figure', $stderr);
    }

    /**
     * A clean environment, as in the container: not the test runner's APP_ENV or verbosity.
     *
     * @return array<string, string>
     */
    private static function environment(string $env): array
    {
        return [
            'PATH' => (string) getenv('PATH'),
            'APP_ENV' => $env,
            'APP_DEBUG' => 'dev' === $env ? '1' : '0',
            'APP_SECRET' => 'stdio-test',
            'FACTS_PATH' => '/nonexistent/facts.sqlite',
            'APP_CACHE_DIR' => self::$dir.'/cache',
            'APP_LOG_DIR' => self::$dir.'/log',
        ];
    }

    /**
     * Runs the STDIO server, sends the messages, closes its input and reads both outputs.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return array{string, string} standard output and standard error
     */
    private static function serve(string $env, array $messages): array
    {
        $root = \dirname(__DIR__, 2);
        $process = proc_open(
            [\PHP_BINARY, $root.'/bin/console', 'mcp:server', '--env='.$env],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            self::environment($env),
        );
        self::assertIsResource($process);

        foreach ($messages as $message) {
            fwrite($pipes[0], json_encode($message, \JSON_THROW_ON_ERROR)."\n");
        }
        fclose($pipes[0]);

        $out = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $deadline = time() + self::TIMEOUT;
        while ([] !== $open && time() < $deadline) {
            $read = array_values($open);
            $write = $except = null;
            if (false === stream_select($read, $write, $except, 1)) {
                break;
            }
            foreach ($read as $stream) {
                $index = array_search($stream, $open, true);
                \assert(1 === $index || 2 === $index);
                $chunk = fread($stream, 8192);
                if (false === $chunk || ('' === $chunk && feof($stream))) {
                    fclose($stream);
                    unset($open[$index]);
                    continue;
                }
                $out[$index] .= $chunk;
            }
        }
        foreach ($open as $stream) {
            fclose($stream);
        }
        proc_terminate($process);
        proc_close($process);

        self::assertSame([], $open, \sprintf("The server did not stop within %d s.\nStandard error:\n%s", self::TIMEOUT, $out[2]));

        return [$out[1], $out[2]];
    }
}
