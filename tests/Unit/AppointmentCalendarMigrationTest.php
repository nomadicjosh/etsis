<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Advising\AdvisingAppointment;
use Domain\Advising\AppointmentTime;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\AlterTable;

final class AppointmentCalendarMigrationTest extends TestCase
{
    public function testOwnerCalendarIndexCompilesForAllFiveSchemaDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261125000005_AddSisAppointmentCalendar.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new AlterTable('sis_advising_appointments');
            \AddSisAppointmentCalendar::define($table);
            $connection = $this->createStub(Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->alter($table), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('saa_owner_calendar', $sql);
            self::assertStringContainsString('owner_user_id', $sql);
            self::assertStringContainsString('starts_at', $sql);
            if ($dialect === 'PostgreSQL') {
                self::assertStringContainsString('sis_advising_appointments_saa_owner_calendar', $sql);
            }
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO/i', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
        }
    }

    public function testSeventeenRealActionsRestoreWithoutChangingEarlierTwelveActionFacts(): void
    {
        $actor = EntityId::generate();
        $item = AdvisingAppointment::create(
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            $actor,
            new AppointmentTime('2026-08-11T10:00', '2026-08-11T10:30', 'UTC'),
            'Fictional instructions.',
            '2026-08-10T17:00:00Z'
        );
        for ($i = 0; $i < 10; $i++) {
            $day = sprintf('2026-09-%02d', $i + 1);
            $item->apply(
                EntityId::generate(),
                $actor,
                'reschedule',
                $i,
                new AppointmentTime($day . 'T10:00', $day . 'T10:30', 'UTC'),
                'Fictional reschedule.',
                '2026-08-10T17:00:00Z'
            );
        }
        $item->apply(EntityId::generate(), $actor, 'held', 10, null, 'Fictional held outcome.', '2026-09-11T17:00:00Z');
        $original = $item->facts();
        for ($i = 0; $i < 5; $i++) {
            $item->apply(
                EntityId::generate(),
                $actor,
                $i % 2 ? 'correct_held' : 'correct_no_show',
                $i + 11,
                null,
                'Fictional reasoned correction.',
                '2026-09-11T17:00:00Z'
            );
        }
        self::assertCount(17, $item->facts()['actions']);
        self::assertSame($original['actions'], array_slice($item->facts()['actions'], 0, 12));
        self::assertSame($item->facts(), AdvisingAppointment::restore($item->facts())->facts());
        $damaged = $item->facts();
        $damaged['actions'][12]['result']['status'] = 'held';
        $this->expectException(CorruptHistoryException::class);
        AdvisingAppointment::restore($damaged);
    }
}
