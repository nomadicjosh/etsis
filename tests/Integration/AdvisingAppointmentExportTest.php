<?php

declare(strict_types=1);

namespace Tests\Integration;

use Application\Service\AdvisingAppointmentExport;
use Application\Service\CampusCalendarLinks;
use Application\View\AdvisingAppointmentCalendar;
use Application\View\CalendarDocument;
use Domain\Advising\AdvisingAppointment;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;
use RuntimeException;
use Tests\Support\AdvisingAppointmentsFixture;

final class AdvisingAppointmentExportTest extends TestCase
{
    private function fixture(): AdvisingAppointmentsFixture
    {
        require_once dirname(__DIR__) . '/Support/AdvisingAppointmentsFixture.php';
        return new AdvisingAppointmentsFixture();
    }

    private function export(AdvisingAppointmentsFixture $f, ?AuditTrail $audit = null): AdvisingAppointmentExport
    {
        $config = Collection::factory([]);
        $config->setConfigKey('app', ['url' => 'https://campus.example.invalid/sis']);
        return new AdvisingAppointmentExport(
            $f->service(),
            new AdvisingAppointmentCalendar(new CampusCalendarLinks($config)),
            $f->sis->access,
            $audit ?? $f->sis->audit,
            $f->sis->transactions,
        );
    }

    private function plain(string $file): string
    {
        return str_replace("\r\n ", '', $file);
    }

    private function uid(string $file): string
    {
        self::assertSame(1, preg_match('/^UID:([a-f0-9]{64}@etsis)\r$/m', $file, $matches));
        return $matches[1];
    }

    public function testPrivateOwnerDownloadUsesUtcAndOpaqueIdentityWithoutParticipantOrConversationDetails(): void
    {
        $f = $this->fixture();
        $id = $f->schedule()['id'];
        $s = $f->sis;
        $s->access->actor = $f->owner;
        $s->access->staff = false;
        $s->access->grants = [];
        $before = $s->sql->rows('sis_advising_appointments');
        $history = \EventStoreFixture::historyDigest($s->sql);
        $audits = count($s->sql->rows('sis_audit'));
        $file = $this->plain($this->export($f)->prepare('2026-08-11'));
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $file);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $file);
        self::assertStringContainsString('DTSTART:20260811T170000Z', $file);
        self::assertStringContainsString('DTEND:20260811T173000Z', $file);
        self::assertStringContainsString('SEQUENCE:1', $file);
        self::assertStringContainsString('STATUS:CONFIRMED', $file);
        self::assertStringContainsString('CLASS:PRIVATE', $file);
        self::assertStringContainsString('TRANSP:OPAQUE', $file);
        self::assertStringContainsString('URL:https://campus.example.invalid/sis/portal/timetable/', $file);
        self::assertSame(
            hash('sha256', 'advising-appointment:' . $f->owner->value . ':' . $id) . '@etsis',
            $this->uid($file)
        );
        foreach (
            [$id, $f->owner->value, $f->host->value, $f->student->value, $f->pursuit->value,
            'Fictional meeting instructions.', 'Fictional Advisor', 'BSCS', 'ATTENDEE:', 'ORGANIZER:',
            'VALARM', 'METHOD:', 'actor_id', 'booking_policy', 'no_show'] as $private
        ) {
            self::assertStringNotContainsString($private, $file);
        }
        self::assertSame($before, $s->sql->rows('sis_advising_appointments'));
        self::assertSame($history, \EventStoreFixture::historyDigest($s->sql));
        $rows = $s->sql->rows('sis_audit');
        self::assertCount($audits + 1, $rows);
        self::assertSame('advising.appointment_calendar_prepared', $rows[array_key_last($rows)]['action']);
        $ordinary = $f->service()->calendar('owner', null, null, '2026-08-11')['items'][0];
        self::assertArrayNotHasKey('version', $ordinary);
        self::assertArrayNotHasKey('updated_at', $ordinary);
    }

    public function testReschedulingAndOptionalCancellationKeepIdentityAndAdvanceRevision(): void
    {
        $f = $this->fixture();
        $id = new EntityId($f->schedule()['id']);
        $f->sis->access->actor = $f->owner;
        $first = $this->export($f)->prepare('2026-08-11');
        $f->sis->access->actor = $f->staff;
        $f->sis->clock->time = $f->sis->clock->time->modify('+1 minute');
        $f->act($id, 'reschedule', time: $f->time('2026-08-12T11:00', '2026-08-12T12:00'));
        $f->sis->access->actor = $f->owner;
        $changed = $this->export($f)->prepare('2026-08-11');
        self::assertSame($this->uid($first), $this->uid($changed));
        self::assertStringContainsString('SEQUENCE:2', $changed);
        self::assertStringContainsString('DTSTART:20260812T180000Z', $changed);
        $f->act($id, 'cancel', 1, 'owner');
        self::assertStringNotContainsString('BEGIN:VEVENT', $this->export($f)->prepare('2026-08-11'));
        $cancelled = $this->export($f)->prepare('2026-08-11', includeCancelled: true);
        self::assertSame($this->uid($first), $this->uid($cancelled));
        self::assertStringContainsString('SEQUENCE:3', $cancelled);
        self::assertStringContainsString("STATUS:CANCELLED\r\nTRANSP:TRANSPARENT", $cancelled);
    }

    public function testPrivilegedStaffAndAdvisorReceiveOnlyTheirOwnEmptyCalendar(): void
    {
        $f = $this->fixture();
        $f->schedule();
        foreach ([$f->staff, $f->advisor, $f->sis->user()] as $other) {
            $f->sis->access->actor = $other;
            $f->sis->access->staff = true;
            self::assertStringNotContainsString('BEGIN:VEVENT', $this->export($f)->prepare('2026-08-11'));
        }
    }

    public function testRelinkingAnAccountDoesNotTransferAnOlderAccountsPrivateAppointment(): void
    {
        $f = $this->fixture();
        $f->schedule();
        $other = $f->sis->user();
        $f->sis->sql->update(
            'sis_persons',
            ['owner_user_id' => $other->value],
            ['id' => $f->sis->admissions->student($f->student)->personId->value]
        );
        foreach ([$f->owner, $other] as $actor) {
            $f->sis->access->actor = $actor;
            self::assertStringNotContainsString('BEGIN:VEVENT', $this->export($f)->prepare('2026-08-11'));
        }
    }

    public function testHistoricalPursuitsAndMultiDayDisplayExportOnceAndExcludeOtherOwners(): void
    {
        $f = $this->fixture();
        $program = EntityId::generate();
        $f->sis->sql->insert('sis_programs', ['id' => $program->value, 'code' => 'ARTS-UG',
            'title' => 'Fictional historical program']);
        $pursuit = new \Domain\Student\StudentProgram(
            EntityId::generate(),
            $f->student,
            $program,
            $f->sis->term,
            'closed'
        );
        new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($f->sis->sql)->addAssignment($pursuit);
        $item = AdvisingAppointment::create(
            EntityId::generate(),
            $f->student,
            $pursuit->id,
            $f->owner,
            $f->host,
            $f->advisor,
            $f->staff,
            $f->time('2026-08-11T10:00', '2026-08-11T11:00'),
            'Fictional historical message.',
            '2026-08-10T17:00:00Z'
        );
        $f->records->save($item, null);
        $other = new AdvisingAppointmentsFixture($f->sis);
        $other->schedule();
        $f->sis->access->actor = $f->owner;
        $f->sis->clock->time = $f->sis->clock->time->setTimezone(new \DateTimeZone('Asia/Yangon'));
        $file = $this->export($f)->prepare('2026-08-11', 'month');
        self::assertSame(1, substr_count($file, 'BEGIN:VEVENT'));
        self::assertStringContainsString('DTSTART:20260811T170000Z', $file);
        self::assertStringContainsString('DTEND:20260811T180000Z', $file);
    }

    #[DataProvider('drifts')]
    public function testPreparationRejectsFinalSourceOrOwnerDriftAndRollsBackItsRequiredAudit(string $drift): void
    {
        $f = $this->fixture();
        $id = new EntityId($f->schedule()['id']);
        $f->sis->access->actor = $f->owner;
        $before = $f->sis->sql->rows('sis_advising_appointments');
        $audits = $f->sis->sql->rows('sis_audit');
        $audit = $f->audit(function () use ($f, $id, $drift): void {
            $s = $f->sis;
            match ($drift) {
                'actor' => $s->access->actor = $f->staff,
                'owner' => $s->sql->update('sis_persons', ['owner_user_id' => $f->staff->value],
                    ['id' => $s->admissions->student($f->student)->personId->value]),
                'corrupt' => $s->sql->update('sis_advising_appointments', ['version' => 9], ['id' => $id->value]),
                'change' => $f->act($id, 'cancel', channel: 'owner'),
                'timezone' => $s->clock->time = $s->clock->time->setTimezone(new \DateTimeZone('UTC')),
                'audit' => throw new RuntimeException('Fictional audit failure.'),
            };
        });
        try {
            $this->export($f, $audit)->prepare('2026-08-11');
            self::fail('A changed private source must not produce a calendar.');
        } catch (\Throwable $error) {
            self::assertInstanceOf(match ($drift) {
                'actor' => AccessDeniedException::class,
                'corrupt' => CorruptHistoryException::class,
                'audit' => RuntimeException::class,
                default => ConcurrentModificationException::class,
            }, $error);
        }
        self::assertSame($before, $f->sis->sql->rows('sis_advising_appointments'));
        self::assertSame($audits, $f->sis->sql->rows('sis_audit'));
    }

    public static function drifts(): array
    {
        return [['actor'], ['owner'], ['corrupt'], ['change'], ['timezone'], ['audit']];
    }

    public function testOverfullMonthIsRejectedCompletelyWhileAWeekRemainsAvailable(): void
    {
        $f = $this->fixture();
        for ($index = 0; $index < 101; $index++) {
            $day = sprintf('2026-08-%02d', 11 + ($index % 20));
            $f->records->save(AdvisingAppointment::create(
                EntityId::generate(),
                $f->student,
                $f->pursuit,
                $f->owner,
                $f->host,
                $f->advisor,
                $f->staff,
                $f->time($day . 'T10:00', $day . 'T10:30'),
                'Fictional completed appointment.',
                '2026-08-10T17:00:00Z'
            ), null);
        }
        $f->sis->access->actor = $f->owner;
        try {
            $this->export($f)->prepare('2026-08-11', 'month');
            self::fail('An overfull export must not return a partial calendar.');
        } catch (ValidationException) {
            self::assertStringContainsString('BEGIN:VCALENDAR', $this->export($f)->prepare('2026-08-11'));
        }
    }

    public function testDstInstantsAndCancelledTransparencyDoNotChangeOtherCalendarDefaults(): void
    {
        $event = ['uid' => hash('sha256', 'fictional DST appointment'), 'version' => 1,
            'updated_at' => '2026-10-30T12:00:00Z', 'starts_at' => '2026-11-01T01:30:00-07:00',
            'ends_at' => '2026-11-01T01:30:00-08:00', 'title' => "Fictional; appointment, \\ \n",
            'description' => '', 'location' => '', 'url' => 'https://campus.example.invalid/portal/timetable/'];
        $encoder = new CalendarDocument();
        $original = $encoder->render([$event], 'Fictional calendar', true);
        self::assertStringContainsString('DTSTART:20261101T083000Z', $original);
        self::assertStringContainsString('DTEND:20261101T093000Z', $original);
        self::assertStringContainsString("STATUS:CONFIRMED\r\nTRANSP:OPAQUE", $original);
        self::assertStringContainsString('STATUS:CANCELLED', $encoder->render(
            [$event + ['status' => 'CANCELLED']],
            'Fictional calendar',
            true
        ));
        $this->expectException(InvalidArgumentException::class);
        $encoder->render([$event + ['status' => "CANCELLED\r\nATTENDEE:secret"]], 'Fictional calendar', true);
    }
}
