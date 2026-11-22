<?php

declare(strict_types=1);

namespace App\Tests\Proof;

use App\Data\Facts;
use App\Data\NoSnapshot;
use App\Layout\Measured;
use App\Proof\MeasuredProof;
use App\Proof\Refusal;
use App\Proof\RefusalReason;
use App\Proof\Resolver;
use App\Tests\Support\CatalogFixture;
use App\Tests\Support\FactsFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResolverTest extends TestCase
{
    private const string URL = 'https://ec.europa.eu/eurostat/databrowser/view/tour_occ_ninat/default/table';

    private string $path;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/go-figure-facts-'.bin2hex(random_bytes(4)).'.sqlite';
        $this->cacheDir = sys_get_temp_dir().'/go-figure-cache-'.bin2hex(random_bytes(4));
        FactsFixture::write($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        array_map(unlink(...), glob($this->cacheDir.'/*') ?: []);
        @rmdir($this->cacheDir);
    }

    public function testACellBecomesAProofWithItsSource(): void
    {
        $proof = $this->resolver()->measured(new Measured('measured', 'nights_total', 'FR', '2024'));

        self::assertInstanceOf(MeasuredProof::class, $proof);
        self::assertSame([
            'v' => 1,
            'type' => 'measured',
            'provider' => 'eurostat',
            'dataset' => 'nights_total',
            'geo' => 'FR',
            'time' => '2024',
            'unit' => 'NR',
            'value' => 457644262.0,
            'status' => '',
            'source_updated' => FactsFixture::SOURCE_UPDATED,
            'snapshot_date' => FactsFixture::SNAPSHOT_DATE,
            'url' => self::URL,
        ], $proof->toArray());
    }

    public function testTheSourceFlagTravelsWithTheValue(): void
    {
        $proof = $this->resolver()->measured(new Measured('measured', 'nights_total', 'FR', '2020'));

        self::assertInstanceOf(MeasuredProof::class, $proof);
        self::assertSame('e', $proof->toArray()['status']);
    }

    /**
     * @return iterable<string, array{string, string, string, RefusalReason}>
     */
    public static function refusedCells(): iterable
    {
        yield 'dataset not in the catalog' => ['nights_hotels', 'FR', '2024', RefusalReason::UnknownDataset];
        yield 'place not covered by the source' => ['nights_total', 'DE', '2024', RefusalReason::PlaceNotCovered];
        yield 'place not covered, even in another case' => ['nights_total', 'fr', '2024', RefusalReason::PlaceNotCovered];
        yield 'period not in the table' => ['nights_total', 'FR', '2030', RefusalReason::CellMissing];
        yield 'neighbouring period is never used' => ['nights_total', 'FR', '2022', RefusalReason::CellMissing];
        yield 'no value at the source' => ['nights_total', 'MT', '2025', RefusalReason::ValueMissing];
    }

    #[DataProvider('refusedCells')]
    public function testACellThatCannotBeProvenIsRefused(string $dataset, string $geo, string $time, RefusalReason $reason): void
    {
        $requested = new Measured('measured', $dataset, $geo, $time);
        $refusal = $this->resolver()->measured($requested);

        self::assertInstanceOf(Refusal::class, $refusal);
        self::assertSame($reason, $refusal->reason);
        self::assertSame($requested, $refusal->requested);
    }

    public function testAMissingSnapshotFailsLoudly(): void
    {
        $this->expectException(NoSnapshot::class);
        $this->expectExceptionMessage('No data snapshot');

        (new Facts('/nonexistent/facts.sqlite'))->find('nights_total', 'FR', '2024');
    }

    public function testAnEmptyFileIsNoSnapshotEither(): void
    {
        $empty = sys_get_temp_dir().'/go-figure-empty-'.bin2hex(random_bytes(4)).'.sqlite';
        touch($empty);

        try {
            $this->expectException(NoSnapshot::class);
            $this->expectExceptionMessage('has no facts table');
            (new Facts($empty))->find('nights_total', 'FR', '2024');
        } finally {
            unlink($empty);
        }
    }

    public function testTheTableIsOpenedReadOnly(): void
    {
        $facts = new Facts($this->path);
        $facts->find('nights_total', 'FR', '2024');

        $pdo = (new \ReflectionProperty(Facts::class, 'pdo'))->getValue($facts);
        self::assertInstanceOf(\Pdo\Sqlite::class, $pdo);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('readonly');
        $pdo->exec("DELETE FROM facts");
    }

    private function resolver(): Resolver
    {
        return new Resolver(CatalogFixture::catalog($this->cacheDir), new Facts($this->path));
    }
}
