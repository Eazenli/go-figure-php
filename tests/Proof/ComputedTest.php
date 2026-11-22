<?php

declare(strict_types=1);

namespace App\Tests\Proof;

use App\Layout\Computed;
use App\Layout\Measured;
use App\Layout\Op;
use App\Proof\ComputedProof;
use App\Proof\ProofToken;
use App\Proof\Refusal;
use App\Proof\RefusalReason;
use App\Proof\Resolver;
use App\Tests\Support\FactsFixture;
use App\Ui\Twig\FigureExtension;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The calculations: the model names an operation and cells, the server checks that
 * they are comparable and computes. One level only, never a composite score; the proof shows the
 * formula and each cell with its own proof, and its link recomputes everything.
 */
final class ComputedTest extends WebTestCase
{
    private KernelBrowser $client;
    private Resolver $resolver;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        FactsFixture::write(self::getContainer()->getParameter('app.facts_path'));
        $this->resolver = self::getContainer()->get(Resolver::class);
    }

    public function testAChangeItsPercentAndARankAreComputedFromProvenCells(): void
    {
        $change = $this->compute(Op::Change, ['nights_total', 'FR', '2019'], ['nights_total', 'FR', '2024']);
        self::assertInstanceOf(ComputedProof::class, $change);
        self::assertSame([11090394.0, 'NR'], [$change->value, $change->unit]);

        $percent = $this->compute(Op::ChangePct, ['nights_total', 'FR', '2019'], ['nights_total', 'FR', '2024']);
        self::assertInstanceOf(ComputedProof::class, $percent);
        self::assertEqualsWithDelta(2.4835, $percent->value, 0.0001);
        self::assertSame(ComputedProof::PERCENT, $percent->unit);

        // Spain is first of the two, France second: the place of the first cell given.
        $rank = $this->compute(Op::Rank, ['nights_total', 'FR', '2024'], ['nights_total', 'ES', '2024']);
        self::assertInstanceOf(ComputedProof::class, $rank);
        self::assertSame(2.0, $rank->value);

        $figures = self::getContainer()->get(FigureExtension::class);
        self::assertSame('+11,090,394', $figures->number($change));
        self::assertSame('+2.5%', $figures->number($percent));
        self::assertSame('2 of 2', $figures->number($rank));
        self::assertSame('France, 2019 to 2024', $figures->caption($percent));
        // A change that rounds to nothing carries no sign; the flags of the cells stay on the result.
        $flat = new ComputedProof(Op::ChangePct, $percent->operands, -0.04, ComputedProof::PERCENT);
        self::assertSame('0.0%', $figures->number($flat));
        $estimated = $this->compute(Op::Change, ['nights_total', 'FR', '2020'], ['nights_total', 'FR', '2024']);
        self::assertInstanceOf(ComputedProof::class, $estimated);
        self::assertSame('e', FigureExtension::flags($estimated));
        self::assertStringContainsString('<sup class="gf-flag" title="estimated">e</sup>', $figures->figure($estimated));
    }

    public function testCellsThatAreNotComparableAreRefused(): void
    {
        $reason = fn (Op $op, array ...$cells): ?RefusalReason => ($r = $this->compute($op, ...$cells)) instanceof Refusal ? $r->reason : null;

        // One cell, or three, for a change.
        self::assertSame(RefusalReason::OperandCount, $reason(Op::Change, ['nights_total', 'FR', '2024']));
        // A cell the data does not have: nothing is computed from a guess.
        self::assertSame(RefusalReason::OperandMissing, $reason(Op::ChangePct, ['nights_total', 'FR', '2019'], ['nights_total', 'UK', '2024']));
        // Nights against degrees: not the same dataset.
        self::assertSame(RefusalReason::NotAChange, $reason(Op::Change, ['nights_total', 'FR', '2024'], ['temp_max_city', 'ES004C', 'M09']));
        // An evolution is one place at two periods: not two places, not the same cell twice.
        self::assertSame(RefusalReason::NotAChange, $reason(Op::Change, ['nights_total', 'FR', '2024'], ['nights_total', 'ES', '2024']));
        self::assertSame(RefusalReason::NotAChange, $reason(Op::ChangePct, ['nights_total', 'FR', '2024'], ['nights_total', 'FR', '2024']));
        // A rank across two years, or of a place against itself.
        self::assertSame(RefusalReason::NotARank, $reason(Op::Rank, ['nights_total', 'FR', '2024'], ['nights_total', 'ES', '2019']));
        self::assertSame(RefusalReason::NotARank, $reason(Op::Rank, ['nights_total', 'FR', '2024'], ['nights_total', 'FR', '2024']));
        // The test catalog does not allow a percent change on a climate normal.
        self::assertSame(RefusalReason::OperationNotOffered, $reason(Op::ChangePct, ['temp_max_city', 'ES004C', 'M01'], ['temp_max_city', 'ES004C', 'M09']));
        // A share needs a part and its whole from one source: two cells of one dataset are not that.
        self::assertSame(RefusalReason::NotAShare, $reason(Op::Share, ['nights_total', 'FR', '2024'], ['nights_total', 'ES', '2024']));
    }

    public function testTheProofOfACalculationShowsItsCellsAndIsComputedAgain(): void
    {
        $percent = $this->compute(Op::ChangePct, ['nights_total', 'FR', '2019'], ['nights_total', 'FR', '2024']);
        self::assertInstanceOf(ComputedProof::class, $percent);

        $crawler = $this->client->request('GET', '/proof/'.$percent->token());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.value', '+2.5%');
        self::assertSelectorTextContains('.method', 'The second value minus the first, in percent of the first.');
        // Each cell with its own proof link.
        self::assertSame(['446,553,868', '457,644,262'], $crawler->filter('.operand a.gf-figure')->each(static fn ($a) => $a->text()));
        self::assertStringContainsString('"op":"change_pct"', $crawler->filter('.canonical')->text());

        // A link whose fingerprint is not the one of the data is not shown.
        $forged = ProofToken::encode(['v' => 1, 'type' => 'computed', 'op' => 'change_pct', 'cells' => [['nights_total', 'FR', '2019'], ['nights_total', 'FR', '2024']], 'fp' => str_repeat('0', 64)]);
        $this->client->request('GET', '/proof/'.$forged);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<int, string> ...$cells dataset, place, period
     */
    private function compute(Op $op, array ...$cells): ComputedProof|Refusal
    {
        return $this->resolver->computed(new Computed('computed', $op, array_values(array_map(static fn (array $c): Measured => new Measured('measured', $c[0] ?? '', $c[1] ?? '', $c[2] ?? ''), $cells))));
    }
}
