<?php

declare(strict_types=1);

namespace App\Tests\Eval;

use App\Ai\AiSettings;
use App\Ai\AskPrompt;
use App\Ai\Cassettes;
use App\Ai\Platforms;
use App\Catalog\Catalog;
use App\Tests\Support\FactsFixture;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * app:eval with a scripted model: it names one expected cell, one cell of the wrong period
 * (sourced but wrong) and one the data does not have (refused).
 */
final class EvalCommandTest extends KernelTestCase
{
    private const string ANSWER = <<<'JSON'
        {"title": "Nights in France", "components": [
            {"kind": "kpi", "label": "Nights in France", "figure": {"type": "measured", "dataset": "nights_total", "geo": "FR", "time": "2024"}},
            {"kind": "kpi", "label": "Nights in France before", "figure": {"type": "measured", "dataset": "nights_total", "geo": "FR", "time": "2019"}},
            {"kind": "kpi", "label": "Nights in Germany", "figure": {"type": "measured", "dataset": "nights_total", "geo": "DE", "time": "2024"}}
        ]}
        JSON;

    private string $questions;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        FactsFixture::write($container->getParameter('app.facts_path'));
        (new Filesystem())->remove($container->getParameter('app.eval_dir'));
        $this->questions = $container->getParameter('kernel.project_dir').'/var/test/questions.jsonl';
        file_put_contents($this->questions, json_encode(['id' => 'q01', 'question' => 'How many nights in France in 2024?', 'expected' => [['dataset' => 'nights_total', 'time' => ['2024'], 'geo' => ['FR']]]])."\n");
    }

    public function testLiveAnswersAreRecordedThenScored(): void
    {
        $container = self::getContainer();
        $chunks = (new \ReflectionMethod(Cassettes::class, 'chunks'))->invoke(null, self::ANSWER);
        $model = new MockHttpClient(new MockResponse($chunks, ['response_headers' => ['content-type' => 'text/event-stream']]));
        $container->set(AiSettings::class, AiSettings::fromEnv('huggingface', '', 'hf_test'));
        // The scripted model, and no network: recording goes through the same client.
        $container->set(Cassettes::class, new Cassettes(new Platforms($container->get('event_dispatcher'), $model), new AskPrompt($container->get(Catalog::class)), $model));

        $live = $this->evaluate(['--live' => true, '--save' => true]);

        self::assertSame(Command::FAILURE, $live->getStatusCode(), 'one figure is sourced but wrong');
        self::assertStringContainsString('Good cells: 1 of 3 (33%), counting each missed question as one. Sourced but wrong: 1. Refused: 1.', $live->getDisplay());
        self::assertFileExists($container->getParameter('app.eval_dir').'/cassettes/huggingface-openai-gpt-oss-120b-cerebras/q01.json');
        self::assertCount(1, glob($container->getParameter('app.eval_dir').'/results/*.json') ?: []);

        // Replayed from the cassette: the same score, without the model.
        $replay = $this->evaluate([]);
        self::assertStringContainsString('Good cells: 1 of 3 (33%)', $replay->getDisplay());
    }

    public function testAQuestionWithoutCassetteIsReportedNotRecorded(): void
    {
        $tester = $this->evaluate([]);

        self::assertStringContainsString('Missed questions: 1 (no page: 0, not recorded: 1, errors: 0).', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function evaluate(array $options): CommandTester
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:eval'));
        $tester->execute(['--questions' => $this->questions, ...$options]);

        return $tester;
    }
}
