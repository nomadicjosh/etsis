<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Advising\AvailabilityPattern;
use Domain\Advising\AdvisingAvailabilitySeries;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class AdvisingAvailabilitySeriesTest extends TestCase
{
    private function pattern(array $changes = []): AvailabilityPattern
    {
        return new AvailabilityPattern(array_replace(['from' => '2026-10-20', 'through' => '2026-11-17',
            'start_time' => '10:00', 'end_time' => '12:00', 'weekdays' => [2], 'interval_weeks' => 1,
            'excluded_dates' => [], 'timezone' => 'America/Los_Angeles'], $changes));
    }
    private function series(): AdvisingAvailabilitySeries
    {
        return AdvisingAvailabilitySeries::create(
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            'staff',
            $this->pattern(),
            30,
            'Fictional instructions.',
            array_map(static fn(): string => EntityId::generate()->value, range(1, 5)),
            '2026-10-01T00:00:00Z'
        );
    }
    public function testCivilPatternRetainsLocalHoursAcrossClockChangesAndMondayAnchoredAlternateWeeks(): void
    {
        $times = $this->pattern()->times(30);
        self::assertCount(5, $times);
        self::assertSame('2026-10-20T17:00:00Z', $times[0]->startsAt);
        self::assertSame('2026-11-03T18:00:00Z', $times[2]->startsAt);
        self::assertSame('2026-11-03T10:00', $times[2]->start);
        $alternate = $this->pattern(['interval_weeks' => 2, 'excluded_dates' => ['2026-11-03']]);
        self::assertSame(['2026-10-20T10:00', '2026-11-17T10:00'], array_map(static fn($t): string =>
            $t->start, $alternate->times(30)));
    }
    #[DataProvider('invalidPatterns')]
    public function testInvalidOrUnboundedPatternsCannotCreateOffers(array $changes): void
    {
        $this->expectException(ValidationException::class);
        $this->pattern($changes)->times(30);
    }
    public static function invalidPatterns(): array
    {
        return [[['through' => '2027-10-20']], [['through' => '2027-04-20']], [['weekdays' => []]],
            [['weekdays' => [2, 2]]], [['interval_weeks' => 0]], [['excluded_dates' => ['2026-10-21']]],
            [['start_time' => '12:00', 'end_time' => '10:00']],
            [['from' => '2026-11-01', 'through' => '2026-11-01', 'weekdays' => [7], 'start_time' =>
                '01:30', 'end_time' => '03:00']],
            [['from' => '2027-03-14', 'through' => '2027-03-14', 'weekdays' => [7], 'start_time' =>
                '02:30', 'end_time' => '03:30']]];
    }
    public function testParentReceiptsReconstructExactMembersClosureAndRedactDebugOutput(): void
    {
        $series = $this->series();
        $actor = EntityId::generate();
        $ids = array_column($series->facts()['windows'], 'id');
        try {
            $series->close($actor, 0, [$ids[0]], 'Fictional expired-window closure.', '2026-11-01T00:00:00Z');
            self::fail('Expired windows cannot acquire a new remaining-window closure.');
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertSame(0, $series->facts()['version']);
        }
        $result = $series->close(
            $actor,
            0,
            array_slice($ids, 2),
            'Fictional remaining closure.',
            '2026-11-01T00:00:00Z'
        );
        self::assertSame($result, $series->close(
            $actor,
            0,
            array_slice($ids, 2),
            'Fictional remaining closure.',
            '2026-12-01T00:00:00Z'
        ));
        self::assertSame($series->facts(), AdvisingAvailabilitySeries::restore($series->facts())->facts());
        self::assertStringNotContainsString($ids[0], json_encode($series));
        self::assertStringNotContainsString('Fictional', json_encode($series));
        self::assertArrayNotHasKey('receipts', $series->view());
    }
    #[DataProvider('damage')]
    public function testAlteredSourceAndReceiptDataFailClosed(string $field): void
    {
        $data = $this->series()->facts();
        match ($field) {
            'time' => $data['windows'][0]['time']['starts_at'] = '2026-10-20T18:00:00Z',
            'receipt' => $data['receipts']['create']['result']['window_ids'] = [],
            'pattern' => $data['pattern']['interval_weeks'] = 2,
            'extra' => $data['invented'] = true,
        };
        $this->expectException(CorruptHistoryException::class);
        AdvisingAvailabilitySeries::restore($data);
    }
    public static function damage(): array
    {
        return [['time'], ['receipt'], ['pattern'], ['extra']];
    }
    public function testSeriesSchemaCompilesForFiveDialectsWithoutChangingExistingFacts(): void
    {
        require_once dirname(__DIR__, 2)
        . '/database/migrations/20261125000007_CreateSisAdvisingAvailabilitySeries.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new CreateTable('sis_advising_availability_series');
            \CreateSisAdvisingAvailabilitySeries::define($table);
            $connection = $this->createStub(Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string =>
                '"' . $name . '"');
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('savs_host_page', $sql);
            self::assertStringContainsString('sealed_data', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
        }
    }
}
