<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\ValidationException;
use Infrastructure\Reporting\SqlRelationalReportSource;
use Infrastructure\Reporting\SqlReportCaptureSource;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ReportCaptureSourceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    private function query(): ReportQuery
    {
        return new ReportQuery(
            ['dataset' => 'fictional', 'columns' => [
                ['field' => 'name', 'label' => 'Fictional name', 'aggregate' => 'none'],
            ], 'sort' => []],
            ['id' => 'fictional', 'table' => 'report_capture_fixture', 'root' => 'item', 'relationships' => [],
                'fields' => ['name' => ['type' => 'string', 'relation' => 'item', 'column' => 'name']]],
        );
    }
    private function seed(SisFixture $s, int $count, string $name): SqlReportCaptureSource
    {
        $pdo = $s->connection->pdo;
        $pdo->exec('CREATE TABLE report_capture_fixture (id VARCHAR(26) PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO report_capture_fixture (id, name) VALUES (?, ?)');
        for ($i = 0; $i < $count; $i++) {
            $insert->execute([str_pad((string) $i, 26, '0', STR_PAD_LEFT), $name]);
        }
        $pdo->commit();
        return new SqlReportCaptureSource($s->connection->queryBuilder(), new SqlRelationalReportSource($s->sql));
    }
    public function testExactlyFiveThousandRowsAreCompleteAndTheReadTransactionEnds(): void
    {
        $s = new SisFixture();
        $source = $this->seed($s, 5000, 'Fictional');
        self::assertCount(5000, $source->capture($this->query())->rows);
        self::assertFalse($s->connection->pdo->inTransaction());
    }
    public function testAnOversizedCaptureRejectsWhollyAndRollsBackItsReadTransaction(): void
    {
        $s = new SisFixture();
        $source = $this->seed($s, 5001, 'Fictional');
        try {
            $source->capture($this->query());
            self::fail('A partial capture must not be returned.');
        } catch (ValidationException) {
            self::assertFalse($s->connection->pdo->inTransaction());
        }
        self::assertSame(5001, (int) $s->connection->pdo
            ->query('SELECT COUNT(*) FROM report_capture_fixture')->fetchColumn());
    }
    public function testWideResultsRespectTheByteLimitAndDoNotLeaveAnOpenTransaction(): void
    {
        $s = new SisFixture();
        $source = $this->seed($s, 20, str_repeat('x', 500000));
        try {
            $source->capture($this->query());
            self::fail('The byte limit must reject the complete capture.');
        } catch (ValidationException) {
            self::assertFalse($s->connection->pdo->inTransaction());
        }
    }
    public function testCaptureCannotCommitOrRollBackAnExistingWriteTransaction(): void
    {
        $s = new SisFixture();
        $source = $this->seed($s, 1, 'Fictional');
        $s->connection->pdo->beginTransaction();
        try {
            $source->capture($this->query());
            self::fail('Capture must own its consistent read transaction.');
        } catch (\RuntimeException) {
            self::assertTrue($s->connection->pdo->inTransaction());
        } finally {
            $s->connection->pdo->rollBack();
        }
    }
}
