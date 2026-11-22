<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AiSettings;
use App\Ai\AskPrompt;
use App\Ai\Cassettes;
use App\Ai\Platforms;
use App\Ai\Questions;
use App\Catalog\Catalog;
use App\Tests\Support\FactsFixture;
use Monolog\Handler\TestHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * /ask: an example question replayed from its cassette without a key, and a free question that
 * needs one. The model is scripted: a layout streamed as chat completion chunks, through the real
 * Hugging Face bridge.
 */
final class AskControllerTest extends WebTestCase
{
    private const string LAYOUT = <<<'JSON'
        {"title": "Nights in France and Spain", "components": [
            {"kind": "kpi", "label": "Nights in France", "figure": {"type": "measured", "dataset": "nights_total", "geo": "FR", "time": "2024"}},
            {"kind": "bar", "title": "Nights by country", "bars": [
                {"type": "measured", "dataset": "nights_total", "geo": "ES", "time": "2024"},
                {"type": "measured", "dataset": "nights_total", "geo": "DE", "time": "2024"}
            ]},
            {"kind": "note", "segments": [{"kind": "text", "text": "Up twelve <b>percent</b>."}]}
        ]}
        JSON;
    private const string KEY = 'hf_test_key_never_shown';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = $this->client->getContainer();
        FactsFixture::write($container->getParameter('app.facts_path'));
        $container->get('cache.rate_limiter')->clear();

        // An example question, its hand-written answer recorded as a cassette for the test catalog.
        $dir = $container->getParameter('kernel.project_dir').'/var/test';
        file_put_contents($dir.'/script.json', self::LAYOUT);
        file_put_contents($container->getParameter('app.questions_file'), <<<'YAML'
            - id: france-spain
              text: "Nights in France and Spain?"
              provider: huggingface
              model: "openai/gpt-oss-120b:cerebras"
              cassette: var/test/cassettes/france-spain.json
              recorded: scripted
              script: var/test/script.json
            YAML);
        $question = $container->get(Questions::class)->get('france-spain');
        self::assertNotNull($question);
        // Recorded outside the container's services, which a test may still replace.
        $cassettes = new Cassettes(new Platforms($container->get('event_dispatcher'), new MockHttpClient()), new AskPrompt($container->get(Catalog::class)), new MockHttpClient());
        $cassettes->record($question, $question->settings(), self::LAYOUT);
    }

    public function testAnExampleIsReplayedWithoutAKey(): void
    {
        $crawler = $this->client->request('GET', '/?q=france-spain');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.origin', 'Replayed: an answer written by hand');
        self::assertSame('457,644,262', $crawler->filter('.gf-kpi .gf-figure')->text());
        self::assertSelectorTextContains('.gf-refusal', 'Missing: This source has no data for this place.');
        self::assertSelectorTextContains('.gf-note', 'Unsupported: the model wrote this number itself.');
        self::assertSelectorTextSame('.tally', 'Figures proven: 2. Figures refused: 1. Texts or periods not shown: 1.');
        self::assertTrue($this->logs()->hasInfoThatContains('ask refused a figure: place_not_covered'));
        // Beside the page, what the model wrote, as plain text: its number is there, not on the page.
        self::assertSelectorTextContains('#model-stream summary', 'See what was sent to the server');
        $raw = $crawler->filter('#model-stream pre')->text();
        self::assertStringContainsString('"components"', $raw);
        // Shown as text, never as HTML: the tags the model wrote are read as written.
        self::assertStringContainsString('Up twelve <b>percent</b>.', $raw);
        self::assertCount(0, $crawler->filter('#model-stream pre b'));
        // The answer sits under the question: its title is a third-level heading, its parts the next.
        self::assertSame('Nights in France and Spain', $crawler->filter('.shown h3.gf-title')->text());
        self::assertCount(2, $crawler->filter('.shown h4.gf-heading'), 'The tile and the bar chart; a note has no heading.');
        self::assertCount(1, $crawler->filter('h1'));
    }

    public function testThePageIsStreamedPartByPartWithWhatTheModelWrites(): void
    {
        $this->client->request('GET', '/ask/stream?q=france-spain');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/event-stream', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $events = $this->events();
        $kinds = array_values(array_filter(array_column($events, 0), static fn (string $k): bool => 'delta' !== $k));
        self::assertSame(['origin', 'title', 'component', 'component', 'component', 'end'], $kinds);
        // The parts come out while the model writes: the first component before the last chunk.
        $first = array_search('component', array_column($events, 0), true);
        self::assertLessThan(\count($events) - 2, $first);
        // Streamed under the question: the title is a third-level heading, its parts the next.
        $title = array_values(array_filter($events, static fn (array $e): bool => 'title' === $e[0]))[0][1] ?? '';
        self::assertStringStartsWith('<h3 class="gf-title">', (string) $title);
        self::assertStringContainsString('<h4 class="gf-heading">', (string) (array_values(array_filter($events, static fn (array $e): bool => 'component' === $e[0]))[0][1] ?? ''));
        // "What the model writes": what the model wrote, whole.
        $written = implode('', array_map(static fn (array $e): string => (string) $e[1], array_filter($events, static fn (array $e): bool => 'delta' === $e[0])));
        self::assertJsonStringEqualsJsonString(self::LAYOUT, $written);
        $last = end($events);
        self::assertIsArray($last);
        self::assertSame('end', $last[0]);
        self::assertSame(['proven' => 2, 'refused' => 1, 'hidden' => 1], \array_slice((array) $last[1], 0, 3));
        // The whole page, at a link that builds it again.
        $this->client->request('GET', (string) ((array) $last[1])['link']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.gf-kpi .gf-figure', '457,644,262');
    }

    public function testTheStreamKeepsTheVisitorsLanguage(): void
    {
        // Symfony sets the language back to English before a streamed response runs: not this one.
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('lang', 'fr'));
        $this->client->request('GET', '/ask/stream?q=france-spain');

        $events = $this->events();
        $html = implode('', array_map(static fn (array $e): string => \is_string($e[1]) ? $e[1] : '', array_filter($events, static fn (array $e): bool => \in_array($e[0], ['title', 'component'], true))));
        self::assertStringContainsString("457\u{202F}644\u{202F}262", $html);
        self::assertStringContainsString('nuitées', $html);
        self::assertStringContainsString('Absent. ', $html);
        $end = end($events);
        self::assertIsArray($end);
        self::assertStringStartsWith("Chiffres prouvés\u{A0}: ", (string) ((array) $end[1])['summary']);
        // The logs stay in English.
        self::assertTrue($this->logs()->hasInfoThatContains('ask did not show a text: Unsupported: the model wrote this number itself.'));
    }

    public function testAStaleCassetteIsNotReplayed(): void
    {
        // The request no longer matches the recording, as after a change of prompt or catalog.
        $file = $this->client->getContainer()->getParameter('kernel.project_dir').'/var/test/cassettes/france-spain.json';
        $cassette = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        $cassette['interactions'][0]['request']['signature'] = $cassette['interactions'][0]['request']['signature_v2'] = md5('another request');
        file_put_contents($file, json_encode($cassette, \JSON_THROW_ON_ERROR));

        $this->client->request('GET', '/?q=france-spain');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.problem', 'The model could not answer. The details are in the application log.');
        self::assertSelectorNotExists('#result .gf-figure');
    }

    public function testAnUnknownExampleIsNotFound(): void
    {
        $this->client->request('GET', '/ask/stream?q=nope');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Without a key no model is asked: the words of the question are matched to the data, and the
     * page says so. The same page as a model would send, read and proven the same way.
     */
    public function testWithoutAKeyAQuestionIsReadByItsWords(): void
    {
        $crawler = $this->client->request('GET', '/');
        // Said where one looks, with questions to click and what can be asked.
        self::assertSelectorTextContains('.no-key .no-key-title', 'This install has no AI key, and it still answers.');
        // Only the questions the data of this machine answers.
        $offered = $crawler->filter('.no-key .by-words a')->each(static fn ($a) => $a->text());
        self::assertContains('Tourism in France and Spain', $offered);
        self::assertNotContains('Flights to Greece and Italy', $offered);
        self::assertSelectorTextContains('.no-key .can-ask', 'Nights spent in tourist accommodation');
        self::assertSelectorExists('#result.is-idle');

        $crawler = $this->client->submit($crawler->filter('#ask-form')->form(['question' => 'Tourism in France and Spain?']));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#result .asked', 'Tourism in France and Spain?');
        self::assertSelectorTextSame('#result .origin', 'Answered without a model: matched France, Spain, Nights spent in tourist accommodation.');
        self::assertSame(['457,644,262', '505,166,012'], $crawler->filter('#result .gf-bars a.gf-figure')->each(static fn ($a) => $a->text()));
        self::assertSelectorTextContains('#result .gf-verified', '2 figures read from Eurostat.');
        // What was sent to the server: the layout, as a model would write it.
        self::assertStringContainsString('"dataset": "nights_total"', $crawler->filter('#model-stream pre')->text());
        self::assertSelectorExists('#result .gf-copy');
        self::assertFalse($this->logs()->hasInfoThatContains('Free question to'));

        // A question to click works like a typed one.
        $crawler = $this->client->click($this->client->request('GET', '/')->filter('.no-key .by-words a')->reduce(static fn ($a) => 'Tourism in France and Spain' === $a->text())->link());
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('#result .gf-bars a.gf-figure'));
    }

    public function testWithoutAKeyALinkToAQuestionIsAnsweredAtOnce(): void
    {
        $crawler = $this->client->request('GET', '/?ask='.rawurlencode('nights in spain in 2024'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#result .origin', 'matched Spain, Nights spent in tourist accommodation, 2024.');
        self::assertSame('505,166,012', $crawler->filter('#result .gf-kpi a.gf-figure')->text());
    }

    /** Under an answer read by its words: the same question for another month or another figure. */
    public function testAnAnswerReadByItsWordsOffersTheNextQuestions(): void
    {
        $crawler = $this->client->request('GET', '/?ask='.rawurlencode('Is Lisbon or Seville warmer in September?'));

        self::assertResponseIsSuccessful();
        // The places named can go, others of the same kind can come; then the months.
        $rows = $crawler->filter('#result .gf-refine p');
        self::assertSame(['Lisbon', 'Seville'], $rows->eq(0)->filter('a.is-on')->each(static fn ($a) => $a->text()));
        self::assertSame('Average daily maximum temperature in the city: Seville in September', $rows->eq(0)->filter('a.is-on')->first()->attr('data-ask'));
        self::assertCount(12, $rows->eq(1)->filter('a'));
        self::assertSame('Sep', $rows->eq(1)->filter('a[aria-current]')->text());
        // Each chip is a question the reader itself answers.
        $crawler = $this->client->click($rows->eq(1)->filter('a')->first()->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#result .origin', 'matched Lisbon, Seville, Average daily maximum temperature in the city, January.');
        $crawler = $this->client->click($crawler->filter('#result .gf-refine p')->eq(0)->filter('a.is-on')->first()->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#result .origin', 'matched Seville, Average daily maximum temperature in the city, January.');
    }

    /** With a key the model answers, and the visitor may still ask without it: nothing is spent. */
    public function testWithAKeyAQuestionCanStillBeReadByItsWords(): void
    {
        $this->client->getContainer()->set(AiSettings::class, AiSettings::fromEnv('anthropic', '', 'sk-ant-secret'));
        $crawler = $this->client->request('GET', '/');
        self::assertSelectorExists('.hero .mode .words-switch input[name="words"]:not([checked])');
        self::assertSelectorTextContains('details.no-key.with-key summary', 'Also works without AI');

        $this->client->request('POST', '/', ['question' => 'Tourism in France and Spain?', 'words' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#result .origin', 'Answered without a model');
        self::assertSelectorExists('input[name="words"][checked]');
        self::assertFalse($this->logs()->hasInfoThatContains('Free question to'));

        $this->client->request('GET', '/?words=1&ask='.rawurlencode('Tourism in France and Spain?'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#result .origin', 'Answered without a model');
    }

    public function testWithoutAKeyTheStreamSendsTheSamePage(): void
    {
        $this->client->request('POST', '/ask/stream', ['question' => 'Tourism in France and Spain?']);

        self::assertResponseIsSuccessful();
        $events = $this->events();
        self::assertSame(['origin', 'delta', 'title', 'component', 'component', 'end'], array_column($events, 0));
        self::assertStringStartsWith('Answered without a model', (string) $events[0][1]);
    }

    /** A question it cannot place gets no page: never an answer that is roughly right. */
    public function testAQuestionWhoseWordsNameNothingIsToldWhatTheDataHas(): void
    {
        $this->client->request('POST', '/', ['question' => 'Where is beer best in France?']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#result .problem', 'The words of this question name no indicator of the data. The data has: Nights spent in tourist accommodation');
        self::assertSelectorNotExists('#result .gf-figure');

        $this->client->request('POST', '/', ['question' => 'Where is tourism highest?']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#result .problem', 'The words of this question name no place of the data. The data has: ');

        $this->client->request('POST', '/ask/stream', ['question' => 'Where is tourism highest?']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('The words of this question name no place', (string) $this->client->getResponse()->getContent());
    }

    public function testTheNextQuestionsKeepTheYearOfTheFirst(): void
    {
        $crawler = $this->client->request('GET', '/?ask='.rawurlencode('nights in spain in 2024'));

        $more = $crawler->filter('#result .gf-refine a.is-more')->first();
        self::assertStringEndsWith(' 2024', (string) $more->attr('data-ask'));
        $this->client->click($more->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#result .origin', '2024.');
    }

    /** The page says the words it did not use, and declines a place it does not have. */
    public function testWhatIsNotUnderstoodIsSaidAndAnUnknownPlaceGetsNoPage(): void
    {
        $crawler = $this->client->request('GET', '/?ask='.rawurlencode('Tourism and beer in France'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#result .origin', 'Answered without a model: matched France, Nights spent in tourist accommodation.');
        // In full view above the answer, not in the small print.
        self::assertSelectorTextSame('#result .left-out:not([hidden])', 'Not understood, and left out: beer.');
        // Under its title, each block says what its figures count.
        self::assertSelectorTextSame('#result .gf-kpi .gf-scope', 'Hotels, holiday homes and campsites; residents and non-residents.');

        // A term of a comparison the data does not know: no page.
        $this->client->request('GET', '/?ask='.rawurlencode('Is Tokyo busier than France for tourism?'));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#result .problem', 'The data does not know "Tokyo": a page without it would answer another question.');
        self::assertSelectorNotExists('#result .gf-figure');

        // Outside a comparison, a word with a capital is no reason to refuse: it is said.
        $this->client->request('GET', '/?ask='.rawurlencode('Tourism in France at Christmas'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#result .left-out', 'Not understood, and left out: christmas.');

        // The stream says the same.
        $this->client->request('POST', '/ask/stream', ['question' => 'Tourism and beer in France']);
        self::assertContains('leftout', array_column($this->events(), 0));
    }

    /** A year the data does not have is never swapped for another: alone, it gets no page. */
    public function testAPeriodTheDataLacksIsSaidNotSwapped(): void
    {
        $this->client->request('GET', '/?ask='.rawurlencode('Tourism in France in 1950'));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#result .problem', 'The data has no figure for 1950. The data has: Nights spent in tourist accommodation: ');
        self::assertSelectorNotExists('#result .gf-figure');
    }

    /** A page is never built by a model from a link, nor from another site. */
    public function testOnlyThePagesOwnFormAsksAModel(): void
    {
        $this->client->getContainer()->set(AiSettings::class, AiSettings::fromEnv('anthropic', '', 'sk-ant-secret'));
        $this->client->request('GET', '/?ask='.rawurlencode('Tourism in France?'));
        self::assertFalse($this->logs()->hasInfoThatContains('Free question to'));

        $this->client->request('POST', '/', ['question' => 'Tourism in France?'], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->logs()->hasInfoThatContains('Free question to'));
    }

    public function testWrongSettingsAreSaidAndQuestionsAreReadByTheirWords(): void
    {
        $this->client->getContainer()->set(AiSettings::class, AiSettings::fromEnv('chatgpt', '', 'sk-key'));

        $this->client->request('GET', '/?q=france-spain');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.no-key', 'The AI key of this install is not read: AI_PROVIDER "chatgpt" is not supported');
        self::assertSelectorTextSame('#result .gf-kpi .gf-figure', '457,644,262');
        $this->client->request('POST', '/ask/stream', ['question' => 'Tourism in France?']);
        self::assertResponseIsSuccessful();
    }

    /** With a key, a link never asks the model: the question waits in the form for the visitor. */
    public function testWithAKeyALinkToAQuestionOnlyFillsTheForm(): void
    {
        $this->client->getContainer()->set(AiSettings::class, AiSettings::fromEnv('anthropic', '', 'sk-ant-secret'));
        $crawler = $this->client->request('GET', '/?ask='.rawurlencode('Tourism in France?'));

        self::assertResponseIsSuccessful();
        self::assertSame('Tourism in France?', $crawler->filter('#question')->attr('value'));
        self::assertSelectorExists('#result.is-idle');
        self::assertSelectorTextContains('.hero .mode.on', 'Answered by claude-sonnet-5-5 (anthropic).');
    }

    public function testTheFormerAddressLandsOnThePage(): void
    {
        $this->client->request('GET', '/ask?q=france-spain');
        self::assertResponseRedirects('/?q=france-spain#result');
        $this->client->request('GET', '/ask');
        self::assertResponseRedirects('/');
    }

    public function testAFailedRecordingKeepsTheCassetteThatWorked(): void
    {
        $container = $this->client->getContainer();
        $question = $container->get(Questions::class)->get('france-spain');
        self::assertNotNull($question);
        $before = (string) file_get_contents($question->cassette);
        $denied = new MockHttpClient(new MockResponse('{"error": "Invalid credentials"}', ['http_code' => 401, 'response_headers' => ['content-type' => 'application/json']]));
        $cassettes = new Cassettes(new Platforms($container->get('event_dispatcher'), $denied), new AskPrompt($container->get(Catalog::class)), $denied);

        try {
            $cassettes->record($question, $question->settings('hf_wrong_key'));
            self::fail('A refused call was recorded.');
        } catch (\Throwable) {
        }

        self::assertSame($before, file_get_contents($question->cassette));
        self::assertFileDoesNotExist($question->cassette.'.recording');
    }

    public function testAFreeQuestionIsAnsweredLiveWithTheKeyNeverShown(): void
    {
        $sent = $this->live();

        $crawler = $this->client->request('POST', '/', ['question' => '  Nights in   France?  ']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.origin', 'Live: answered by openai/gpt-oss-120b via cerebras (huggingface).');
        self::assertSame('457,644,262', $crawler->filter('.gf-kpi .gf-figure')->text());
        $request = $sent[0] ?? null;
        self::assertNotNull($request);
        self::assertSame('Bearer '.self::KEY, $request['headers']['authorization']);
        self::assertStringContainsString('"content":"Nights in France?"', $request['body']);
        self::assertStringNotContainsString(self::KEY, (string) $this->client->getResponse()->getContent());
        foreach ($this->logs()->getRecords() as $record) {
            self::assertStringNotContainsString(self::KEY, $record->message.json_encode($record->context));
        }
    }

    public function testAFreeQuestionFromAnotherSiteIsRefused(): void
    {
        $sent = $this->live();

        $this->client->request('POST', '/ask/stream', ['question' => 'Nights in France?'], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseStatusCodeSame(403);
        // Without Fetch Metadata, the Origin header says where the form was.
        $this->client->request('POST', '/ask/stream', ['question' => 'Nights in France?'], server: ['HTTP_ORIGIN' => 'http://evil.example']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/ask/stream', ['question' => 'Nights in France?'], server: ['HTTP_ORIGIN' => 'null']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $sent->getArrayCopy());
    }

    public function testFreeQuestionsAreLimited(): void
    {
        $this->live();
        for ($i = 0; $i < 10; ++$i) {
            $this->client->request('POST', '/ask/stream', ['question' => 'Nights in France?']);
            self::assertResponseIsSuccessful();
        }

        $this->client->request('POST', '/ask/stream', ['question' => 'Nights in France?']);

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
    }

    public function testAnEmptyOrLongQuestionIsRefused(): void
    {
        $this->live();

        $this->client->request('POST', '/ask/stream', ['question' => '   ']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/ask/stream', ['question' => str_repeat('a', 301)]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * A key in the settings, and a provider that streams LAYOUT back.
     *
     * @return \ArrayObject<int, array{headers: array<string, string>, body: string}> the requests sent
     */
    private function live(): \ArrayObject
    {
        $sent = new \ArrayObject();
        $container = $this->client->getContainer();
        $chunks = (new \ReflectionMethod(Cassettes::class, 'chunks'))->invoke(null, self::LAYOUT);
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($sent, $chunks): MockResponse {
            $headers = [];
            foreach ($options['headers'] ?? [] as $line) {
                [$name, $value] = explode(': ', (string) $line, 2);
                $headers[strtolower($name)] = $value;
            }
            $sent->append(['headers' => $headers, 'body' => (string) ($options['body'] ?? '')]);

            return new MockResponse($chunks, ['response_headers' => ['content-type' => 'text/event-stream']]);
        });
        $container->set(AiSettings::class, AiSettings::fromEnv('huggingface', '', self::KEY));
        $container->set(Platforms::class, new Platforms($container->get('event_dispatcher'), $http));

        return $sent;
    }

    /**
     * @return list<array{0: string, 1: mixed}> the server-sent events of the last response
     */
    private function events(): array
    {
        $events = [];
        foreach (explode("\n\n", trim((string) $this->client->getInternalResponse()->getContent())) as $block) {
            if (1 !== preg_match('/^event: (.+)\ndata: (.*)$/s', $block, $m)) {
                self::fail('Not a server-sent event: '.$block);
            }
            $events[] = [$m[1], json_decode($m[2], true, flags: \JSON_THROW_ON_ERROR)];
        }

        return $events;
    }

    private function logs(): TestHandler
    {
        return $this->client->getContainer()->get('monolog.handler.go_figure');
    }
}
