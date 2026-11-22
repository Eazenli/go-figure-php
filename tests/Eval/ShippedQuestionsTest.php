<?php

declare(strict_types=1);

namespace App\Tests\Eval;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The questions of eval/ are read by their words against the catalog and the data of the image,
 * not against the fixtures of the tests: an entry added to the catalog must not take the words of
 * another one.
 */
final class ShippedQuestionsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function sets(): iterable
    {
        yield 'ours' => ['eval/questions.jsonl', 'en'];
        yield 'held out' => ['eval/questions-heldout.jsonl', 'en'];
        yield 'the wider catalog' => ['eval/questions-wide.jsonl', 'en'];
        yield 'the team, in English' => ['eval/questions-team-en.jsonl', 'en'];
        yield 'the team, in French' => ['eval/questions-team-fr.jsonl', 'fr'];
        yield 'the second set of the team, in English' => ['eval/questions-team2-en.jsonl', 'en'];
        yield 'the second set of the team, in French' => ['eval/questions-team2-fr.jsonl', 'fr'];
    }

    #[DataProvider('sets')]
    public function testASetOfQuestionsIsReadByItsWords(string $questions, string $lang): void
    {
        $root = \dirname(__DIR__, 2);
        if (!is_file($root.'/data/facts.sqlite')) {
            self::markTestSkipped('No data snapshot here: run make refresh.');
        }
        // Another environment than the tests': theirs reads the fixtures.
        $command = array_map(escapeshellarg(...), [\PHP_BINARY, 'bin/console', 'app:eval', '--words', '--lang='.$lang, '--questions='.$questions, '--env=dev', '--no-debug']);
        $process = proc_open(implode(' ', $command).' 2>&1', [1 => ['pipe', 'w']], $pipes, $root, ['APP_ENV' => 'dev', 'FACTS_PATH' => ''] + getenv());
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);

        self::assertSame(0, proc_close($process), $output);
        self::assertStringContainsString('The bar is met.', $output);
    }
}
