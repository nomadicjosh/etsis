<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\EventSourcing\EventPage;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Infrastructure\Projection\SqlMeetingPlanVerifier;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Database;
use Qubus\Expressive\Schema;

final class MeetingPlanVerificationSqlTest extends TestCase
{
    public static function drivers(): array
    {
        return array_map(static fn(string $driver): array => [$driver], ['mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oci']);
    }

    #[DataProvider('drivers')]
    public function testRoutingPointerAndHeadQueriesAreReadOnlyBoundedAndParameterized(string $driver): void
    {
        $section = EntityId::generate();
        $course = EntityId::generate();
        $term = EntityId::generate();
        $captured = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn($driver);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('prepare')->willReturnCallback(function (
            string $query
        ) use (
            &$captured,
            $section,
            $course,
            $term
        ): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function (
                array $values
            ) use (
                $query,
                &$captured
            ): bool {
                $captured[] = [$query, $values];
                return true;
            });
            $row = match (true) {
                str_contains($query, 'FROM "sis_sections"') => ['id' => $section->value,
                    'course_id' => $course->value, 'term_id' => $term->value],
                str_contains($query, 'FROM "sis_courses"') => ['id' => $course->value],
                str_contains($query, 'FROM "sis_terms"') => ['id' => $term->value],
                default => false,
            };
            $statement->method('fetch')->willReturnOnConsecutiveCalls($row, false);
            return $statement;
        });
        $schema = $this->createStub(Schema::class);
        $schema->method('hasTable')->willReturn(false);
        $connection = $this->createStub(Connection::class);
        $connection->method(PropertyHook::get('pdo'))->willReturn($pdo);
        $connection->method('getSchema')->willReturn($schema);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn(string $value): string => '"' . $value . '"'
        );
        $database = $this->createStub(Database::class);
        $database->method('getConnection')->willReturn($connection);
        $history = $this->createMock(HistoricalEventStore::class);
        $history->expects(self::never())->method('append');
        $history->expects(self::never())->method('latest');
        $history->expects(self::once())->method('page')->willReturn(new EventPage([], null, 0));
        $result = new SqlMeetingPlanVerifier(new SqlRecords($database), $history)->verify($section);
        self::assertSame('observed_empty', $result->status);
        self::assertCount(12, $captured);
        foreach ($captured as [$query, $values]) {
            self::assertStringStartsWith('SELECT', $query);
            self::assertStringContainsString(match ($driver) {
                'sqlsrv' => 'TOP (2)', 'oci' => 'ROWNUM <= 2', default => 'LIMIT 2',
            }, $query);
            self::assertStringNotContainsString($section->value, $query);
            self::assertNotEmpty($values);
            self::assertStringNotContainsString('sis_bookings', $query);
            self::assertStringNotContainsString('sis_enrollments', $query);
            self::assertStringNotContainsString('sis_history_events', $query);
        }
        self::assertSame([$section->value], $captured[0][1]);
        self::assertSame(['section_meeting_plan', $section->value], $captured[4][1]);
        self::assertSame(['section_publication', $section->value], $captured[5][1]);
    }
}
