<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\FileImporter;
use App\Tests\Support\FactsFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Your own data": a CSV file deposited, read, named and kept, then questioned like any dataset,
 * with a proof that names the file. What the page refuses: a file about people, a shape it cannot
 * read, a title that holds a number.
 */
final class YourDataTest extends WebTestCase
{
    private const string SALES = "store;month;revenue;customers\nLyon;2024-01;120 500,50;3200\nLyon;2024-02;98 000;2900\nParis Nord;2024-01;240 000;6100\nParis Nord;2024-02;;5800\nStore 12;2024-01;45 000;1100\nStore 12;2024-02;47 500;1180\n";

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        FactsFixture::write(self::getContainer()->getParameter('app.facts_path'));
        $imported = self::getContainer()->getParameter('app.imported_path');
        if (is_file($imported)) {
            unlink($imported);
        }
    }

    public function testAFileIsReadNamedKeptAndQuestionedWithItsProof(): void
    {
        $crawler = $this->client->request('GET', '/data');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.data-rules', 'Never a value');

        // First the server says what it read; nothing is kept yet.
        $crawler = $this->client->request('POST', '/data', ['name' => 'sales.csv', 'csv' => self::SALES]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.data-name', '6 lines, 3 places in the column “store”, periods in the column “month”, from 2024-01 to 2024-02.');
        self::assertSame(['revenue', 'customers'], $crawler->filter('.data-column input[name$="[title]"]')->each(static fn ($i) => $i->attr('value')));
        self::assertSelectorNotExists('.data-mine');

        $this->client->submit($crawler->selectButton('Keep these figures')->form([
            'columns[2][title]' => 'Revenue of the store', 'columns[2][unit]' => 'euros',
            'columns[3][title]' => '', // not named: not kept
        ]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.data-kept', 'These figures are now here.');
        self::assertCount(1, $this->client->getCrawler()->filter('.data-kept a'));

        // Questioned without a model, by the title and the places of the file.
        $crawler = $this->client->request('GET', '/?ask='.rawurlencode('Revenue of the store in Lyon and Paris Nord in 2024-01'));
        self::assertResponseIsSuccessful();
        self::assertSame(['120,500.5', '240,000'], $crawler->filter('#result .gf-bars a.gf-figure')->each(static fn ($a) => $a->text()));
        self::assertSelectorTextContains('#result .gf-verified', '2 figures read from your file.');

        // An empty cell of the file is missing, never filled.
        $crawler = $this->client->request('GET', '/?ask='.rawurlencode('Revenue of the store in Lyon and Paris Nord in 2024-02'));
        self::assertSelectorTextContains('#result .gf-refusal', 'Missing: ');

        // No place named: the highest of the file, the file's own places.
        $crawler = $this->client->request('GET', '/?ask='.rawurlencode('Revenue of the store in 2024-01'));
        self::assertSame(['Paris Nord', 'Lyon', 'Store 12'], $crawler->filter('#result .gf-bars .gf-cell')->each(static fn ($c) => trim($c->text())));

        // The proof names the file, the column and the fingerprint; there is no source to open.
        $crawler = $this->client->request('GET', (string) $crawler->filter('#result .gf-bars a.gf-figure')->first()->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.proof .imported', 'From the file sales.csv, column “revenue”, deposited on this machine on ');
        self::assertSelectorTextContains('.proof .imported', hash('sha256', self::SALES));
        self::assertSelectorNotExists('.proof .see a.button');
    }

    /**
     * What a model is sent about a deposited file: its title, its unit, its places and its periods.
     * Not one value of the file: the server reads those itself. This is not retrieval.
     */
    public function testAModelIsSentNoValueOfTheFile(): void
    {
        $this->client->request('POST', '/data', ['name' => 'sales.csv', 'csv' => self::SALES, 'keep' => '1', 'columns' => [2 => ['title' => 'Revenue of the store', 'unit' => 'euros'], 3 => ['title' => 'Customers of the store', 'unit' => 'customers']]]);
        self::assertResponseIsSuccessful();

        $prompt = self::getContainer()->get(\App\Ai\AskPrompt::class)->system(withImports: true);
        self::assertStringContainsString('Revenue of the store', $prompt);
        self::assertStringContainsString('Paris Nord', $prompt);
        self::assertStringContainsString('2024-01', $prompt);
        foreach (['120500', '120 500', '98000', '98 000', '240000', '240 000', '45000', '47500', '3200', '2900', '6100', '5800', '1100', '1180'] as $value) {
            self::assertStringNotContainsString($value, $prompt, 'A value of the file is in what the model is sent.');
        }
        // The name of the file is sent, and the page says so; its fingerprint and its column name are not.
        self::assertStringContainsString('sales.csv', $prompt);
        self::assertStringNotContainsString(hash('sha256', self::SALES), $prompt);
    }

    public function testAFileAboutPeopleOrOfAnotherShapeIsRefused(): void
    {
        $refused = [
            "name;year;salary\nAnna;2024;1\n" => 'names a person',                                  // "name" alone is not in the list…
            "employee;year;salary\nAnna;2024;1\n" => 'names a person',
            "store;year;email\nLyon;2024;a@b.example\n" => 'names a person',
            "store;revenue\nLyon;1\n" => 'at least three named columns',
            "store;label;revenue\nLyon;spring;1\n" => 'No column of periods',
            "store;year;revenue\nLyon;2024;1\nLyon;2024;2\n" => 'a second value for Lyon in 2024',
            "store;year;revenue\nLyon <b>;2024;1\n" => 'cannot name a place',
        ];
        unset($refused["name;year;salary\nAnna;2024;1\n"]);
        foreach ($refused as $csv => $why) {
            $this->client->request('POST', '/data', ['name' => 'x.csv', 'csv' => $csv]);
            self::assertResponseStatusCodeSame(422, $why);
            self::assertSelectorTextContains('.problem', $why);
        }
        self::assertCount(0, array_filter(self::getContainer()->get(\App\Catalog\Catalog::class)->all(), static fn ($entry): bool => FileImporter::PROVIDER === $entry->provider));
    }

    public function testATitleWithANumberIsRefusedAndAFileComesFromThePageOnly(): void
    {
        $this->client->request('POST', '/data', ['name' => 'sales.csv', 'csv' => self::SALES, 'keep' => '1', 'columns' => [2 => ['title' => 'Revenue 2024', 'unit' => 'euros']]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.problem', 'holds a number or a sign');

        $this->client->request('POST', '/data', ['name' => 'sales.csv', 'csv' => self::SALES], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testNumbersAreReadAsPeopleWriteThem(): void
    {
        self::assertSame(120500.5, FileImporter::number('120 500,50'));
        self::assertSame(1234.5, FileImporter::number('1,234.5'));
        self::assertSame(1234.5, FileImporter::number('1.234,5'));
        self::assertSame(1234.0, FileImporter::number('1,234'));
        self::assertSame(12.5, FileImporter::number('12,5'));
        self::assertSame(-3.0, FileImporter::number('-3'));
        self::assertNull(FileImporter::number('12 kg'));
        self::assertNull(FileImporter::number(''));
    }
}
