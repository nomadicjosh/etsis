<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Advising\AdvisingAppointment;
use Domain\Advising\AppointmentTime;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class AdvisingAppointmentTest extends TestCase
{
    private function appointment(): AdvisingAppointment
    {
        return AdvisingAppointment::create(
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            new AppointmentTime('2026-08-11T10:00', '2026-08-11T10:30', 'America/Los_Angeles'),
            'Fictional meeting instructions.',
            '2026-08-10T17:00:00Z'
        );
    }

    #[DataProvider('invalidTimes')]
    public function testTimeRequiresARealUniqueSameDayBoundedCivilMinute(string $start, string $end): void
    {
        $this->expectException(ValidationException::class);
        new AppointmentTime($start, $end, 'America/Los_Angeles');
    }

    public static function invalidTimes(): array
    {
        return [['2026-02-30T10:00', '2026-02-30T11:00'], ['2026-08-11T10:00', '2026-08-11T10:04'],
            ['2026-08-11T10:00', '2026-08-11T14:01'], ['2026-08-11T23:30', '2026-08-12T00:30'],
            ['2026-08-11T10:00', '2026-08-11T09:30'], ['2026-03-08T02:15', '2026-03-08T03:00'],
            ['2026-11-01T01:15', '2026-11-01T02:00']];
    }

    public function testUtcInstantsAreExactAndOriginalFactsRoundTripWithoutExposingMessages(): void
    {
        $item = $this->appointment();
        self::assertSame('2026-08-11T17:00:00Z', $item->facts()['time']['starts_at']);
        self::assertSame($item->facts(), AdvisingAppointment::restore($item->facts())->facts());
        self::assertStringNotContainsString('Fictional', json_encode($item));
        self::assertStringNotContainsString('Fictional', print_r($item, true));
    }

    #[DataProvider('damagedFacts')]
    public function testSealedFactsAreFullyReconstructedAndDamageIsRejected(string $damage): void
    {
        $data = $this->appointment()->facts();
        match ($damage) {
            'version' => $data['version'] = 9,
            'time' => $data['time']['starts_at'] = '2026-08-12T17:00:00Z',
            'receipt' => $data['actions'][0]['result']['status'] = 'held',
            'intent' => $data['actions'][0]['intent'] = str_repeat('0', 64),
            'extra' => $data['extra'] = 'Unexpected',
            'actions' => $data['actions'] = [],
        };
        $this->expectException(CorruptHistoryException::class);
        AdvisingAppointment::restore($data);
    }

    public static function damagedFacts(): array
    {
        return [['version'], ['time'], ['receipt'], ['intent'], ['extra'], ['actions']];
    }

    public function testTenReschedulesRemainBoundedAndStillPermitExplicitCancellation(): void
    {
        $item = $this->appointment();
        $actor = EntityId::generate();
        for ($i = 0; $i < 10; $i++) {
            $day = sprintf('2026-09-%02d', $i + 1);
            $item->apply(
                EntityId::generate(),
                $actor,
                'reschedule',
                $i,
                new AppointmentTime($day . 'T10:00', $day . 'T10:30', 'America/Los_Angeles'),
                'Fictional reschedule.',
                '2026-08-10T17:00:00Z'
            );
        }
        try {
            $item->apply(
                EntityId::generate(),
                $actor,
                'reschedule',
                10,
                new AppointmentTime('2026-09-11T10:00', '2026-09-11T10:30', 'America/Los_Angeles'),
                'Too many reschedules.',
                '2026-08-10T17:00:00Z'
            );
            self::fail('Expected the bounded time-change limit.');
        } catch (ConcurrentModificationException) {
            self::assertSame(10, $item->facts()['version']);
        }
        $item->apply(
            EntityId::generate(),
            $actor,
            'cancel',
            10,
            null,
            'Fictional cancellation.',
            '2026-08-10T17:00:00Z'
        );
        self::assertCount(12, $item->facts()['actions']);
        self::assertSame($item->facts(), AdvisingAppointment::restore($item->facts())->facts());
    }

    public function testPortableUlidSchedulingSchemaCompilesWithoutChangingExistingFacts(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261125000004_CreateSisAdvisingAppointments.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new CreateTable('sis_advising_appointments');
            \CreateSisAdvisingAppointments::define($table);
            $connection = $this->createStub(Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            foreach (['host_person_id', 'saa_host_time', 'saa_pursuit_page', 'sealed_data'] as $field) {
                self::assertStringContainsString($field, $sql);
            }
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
        }
    }
}
