<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\Question;
use App\Ai\Questions;
use PHPUnit\Framework\TestCase;

/**
 * The day a key arrives, `make key-check c=--record` records the example questions and notes it in
 * demo/questions.yaml: only the lines of the question change, whatever the provider of the key.
 */
final class QuestionsTest extends TestCase
{
    public function testARecordingIsNotedInTheFileWithoutTouchingTheRest(): void
    {
        $file = sys_get_temp_dir().'/questions-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($file, <<<'YAML'
            # A comment that stays.
            - id: first
              text: "One?"
              provider: huggingface
              model: "openai/gpt-oss-120b:cerebras"
              cassette: demo/cassettes/first.json
              recorded: scripted
              script: demo/scripts/first.json

            - id: second
              text: "Two?"
              provider: huggingface
              model: "openai/gpt-oss-120b:cerebras"
              cassette: demo/cassettes/second.json
              recorded: scripted
            YAML);
        $questions = new Questions($file, sys_get_temp_dir());

        $questions->markLive('first', 'openai', 'gpt-4.1-mini', '2026-11-20');
        $questions->markLive('first', 'openai', 'gpt-4.1-mini', '2026-11-21');

        $all = $questions->all();
        self::assertSame(['openai', 'gpt-4.1-mini', Question::LIVE, '2026-11-21'], [$all['first']->provider, $all['first']->model, $all['first']->recorded, $all['first']->recordedOn]);
        self::assertSame(['huggingface', Question::SCRIPTED], [$all['second']->provider, $all['second']->recorded]);
        $text = (string) file_get_contents($file);
        self::assertStringStartsWith('# A comment that stays.', $text);
        self::assertSame(1, substr_count($text, 'recorded_on'));

        try {
            $questions->markLive('third', 'openai', 'gpt-4.1-mini', '2026-11-20');
            self::fail('An unknown question is an error.');
        } catch (\LogicException) {
        } finally {
            unlink($file);
        }
    }
}
