<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Advising\AppointmentTime;
use Domain\Advising\BookingPolicySnapshot;
use Domain\Advising\ClassCalendar;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StudentClassCalendarTest extends TestCase
{
    public function testHalfOpenIntervalsAndUtcClockChangesWithoutPrivateSerialization(): void
    {
        $range = new AppointmentTime('2026-11-02T09:00', '2026-11-02T13:00', 'America/Los_Angeles');
        $calendar = new ClassCalendar($range, hash('sha256', 'fictional sources'), [
            ['2026-11-02T18:00:00Z', '2026-11-02T18:30:00Z'],
            ['2026-11-02T19:00:00Z', '2026-11-02T20:00:00Z'],
        ]);
        foreach (
            ['09:30' => true, '09:45' => false, '10:00' => false, '10:15' => false,
            '10:30' => true, '10:45' => false, '11:30' => false, '12:00' => true] as $start => $allowed
        ) {
            $date = '2026-11-02T' . $start;
            $end = new \DateTimeImmutable($date)->modify('+30 minutes')->format('Y-m-d\TH:i');
            self::assertSame($allowed, $calendar->permits(new AppointmentTime($date, $end, 'America/Los_Angeles')));
        }
        self::assertStringNotContainsString('2026', json_encode($calendar));
        self::assertStringNotContainsString('fictional', print_r($calendar, true));
        $before = new AppointmentTime('2026-11-02T08:30', '2026-11-02T09:00', 'America/Los_Angeles');
        $after = new AppointmentTime('2026-11-02T12:45', '2026-11-02T13:15', 'America/Los_Angeles');
        self::assertFalse($calendar->permits($before));
        self::assertFalse($calendar->permits($after));
    }

    public function testEarlierThreeRuleFactsStayExactAndExplicitClassModesAreRetained(): void
    {
        $old = BookingPolicySnapshot::defaults();
        self::assertFalse($old->blocksStudentClasses());
        self::assertFalse($old->blocksAdvisorTeaching());
        self::assertCount(3, $old->facts()['rules']);
        $new = new BookingPolicySnapshot(
            EntityId::generate(),
            1,
            BookingPolicySnapshot::DEFAULTS + ['student_class_mode' => 'block']
        );
        self::assertTrue($new->blocksStudentClasses());
        self::assertSame($new->facts(), BookingPolicySnapshot::from($new->facts())->facts());
        $both = new BookingPolicySnapshot(
            EntityId::generate(),
            2,
            ['advisor_teaching_mode' => 'block', ...BookingPolicySnapshot::DEFAULTS, 'student_class_mode' => 'block']
        );
        self::assertTrue($both->blocksAdvisorTeaching());
        self::assertSame(
            [...array_keys(BookingPolicySnapshot::DEFAULTS), 'student_class_mode',
            'advisor_teaching_mode'],
            array_keys($both->rules)
        );
    }

    #[DataProvider('invalidModes')]
    public function testClassModesAreStrict(mixed $mode): void
    {
        $this->expectException(ValidationException::class);
        new BookingPolicySnapshot(
            EntityId::generate(),
            1,
            BookingPolicySnapshot::DEFAULTS + ['student_class_mode' => $mode]
        );
    }

    public static function invalidModes(): array
    {
        return [[null], [true], ['warn'], [''], [1]];
    }

    #[DataProvider('invalidModes')]
    public function testTeachingModesAreStrict(mixed $mode): void
    {
        $this->expectException(ValidationException::class);
        new BookingPolicySnapshot(
            EntityId::generate(),
            1,
            BookingPolicySnapshot::DEFAULTS + ['advisor_teaching_mode' => $mode]
        );
    }

    public function testCanonicalTeachingIndexCompilesAcrossAllFiveSchemaDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261125000009_AddSisAdvisorCalendarIndex.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new \Qubus\Expressive\Schema\AlterTable('sis_section_versions');
            \AddSisAdvisorCalendarIndex::define($table);
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn(string $s): string => '"' . $s . '"'
            );
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->alter($table), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('faculty_person_id', $sql);
            self::assertStringContainsString('ssv_person_dates', $sql);
            self::assertDoesNotMatchRegularExpression(
                '/DROP |DELETE FROM|INSERT INTO|UPDATE |sis_history_events/i',
                $sql
            );
        }
    }
}
