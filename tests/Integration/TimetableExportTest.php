<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TimetableExportFixture;

final class TimetableExportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/TimetableExportFixture.php';
    }

    public function testOwnerLearningAndTeachingExportsOnePrivateBusyMeetingAndOnlyMinimalPreparationAudit(): void
    {
        $x = new TimetableExportFixture();
        $f = $x->school->sis;
        $before = $x->facts();
        $audits = $f->sql->rows('sis_audit');
        $file = str_replace("\r\n ", '', $x->export()->prepare($f->term, '2026-08-10'));
        self::assertSame(1, preg_match_all('/^BEGIN:VEVENT\r$/m', $file));
        self::assertStringContainsString('CLASS:PRIVATE', $file);
        self::assertStringContainsString('TRANSP:OPAQUE', $file);
        self::assertStringContainsString('DTSTART:20260811T063000Z', $file);
        self::assertStringContainsString('DTEND:20260811T080000Z', $file);
        self::assertStringContainsString('URL:https://campus.example.invalid/portal/timetable/', $file);
        self::assertStringContainsString('Online · No room', $file);
        foreach (
            ['PRIVATE', 'protected publication reason', $f->access->actor->value,
            $x->school->section->id->value, $x->school->student->id->value,
            'VALARM', 'ATTENDEE', 'CLASS:PUBLIC'] as $private
        ) {
            self::assertStringNotContainsString($private === 'PRIVATE' ? 'PRIVATE booking' : $private, $file);
        }
        self::assertSame($before, $x->facts());
        $now = $f->sql->rows('sis_audit');
        self::assertCount(count($audits) + 1, $now);
        self::assertSame('timetable.calendar_prepared', $now[array_key_last($now)]['action']);
        self::assertArrayNotHasKey('plan_version', $x->school->service()->timetable($f->term)['items'][0]);
        self::assertArrayNotHasKey('published_at', $x->school->service()->timetable($f->term)['items'][0]);
    }

    public function testBothStudentOnlyAndInstructorOnlyMembershipsAreEligible(): void
    {
        foreach (['learning', 'teaching'] as $only) {
            $x = new TimetableExportFixture();
            $f = $x->school->sis;
            if ($only === 'learning') {
                $f->sql->update(
                    'sis_sections',
                    ['faculty_user_id' => $f->user()->value],
                    ['id' => $x->school->section->id->value]
                );
            } else {
                $f->sql->update('sis_enrollments', ['status' => 'D'], ['student_id' => $x->school->student->id->value]);
            }
            self::assertStringContainsString('BEGIN:VEVENT', $x->export()->prepare($f->term));
        }
    }

    public function testUnrelatedPrivilegedStaffCannotExportAnotherPersonsSchedule(): void
    {
        $x = new TimetableExportFixture();
        $f = $x->school->sis;
        $f->access->actor = $f->user();
        $f->access->staff = true;
        $this->expectException(AccessDeniedException::class);
        $x->export()->prepare($f->term);
    }

    public function testMissingAndExplicitlyEmptyPlansExportAnEmptyCalendarWithoutInventingTimes(): void
    {
        $x = new TimetableExportFixture(false);
        $f = $x->school->sis;
        self::assertStringNotContainsString('BEGIN:VEVENT', $x->export()->prepare($f->term));
        $f->access->staff = true;
        $f->sql->update('sis_bookings', ['status' => 'cancelled'], ['owner_user_id' => $f->access->actor->value]);
        $x->school->publish();
        $f->access->staff = false;
        self::assertStringNotContainsString('BEGIN:VEVENT', $x->export()->prepare($f->term));
    }

    public function testRequiredAuditFailureReturnsNoFileAndRollsBackPreparation(): void
    {
        $x = new TimetableExportFixture();
        $before = $x->facts();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('Fictional audit failure.'));
        try {
            $x->export($audit)->prepare($x->school->sis->term);
            self::fail('An unaudited private calendar was released.');
        } catch (RuntimeException $error) {
            self::assertSame('Fictional audit failure.', $error->getMessage());
        }
        self::assertSame($before, $x->facts());
    }

    public function testOwnerMembershipAndProtectedPlanChangesBeforeCompletionWithholdTheFile(): void
    {
        foreach (['owner', 'membership', 'plan'] as $change) {
            $x = new TimetableExportFixture();
            $f = $x->school->sis;
            $other = $f->user();
            $before = $x->facts();
            $audits = $f->sql->rows('sis_audit');
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($x, $f, $other, $change): void {
                $f->audit->record($f->access->actor, 'timetable.calendar_prepared', $f->term);
                if ($change === 'owner') {
                    $f->access->actor = $other;
                } elseif ($change === 'membership') {
                    $f->sql->update(
                        'sis_enrollments',
                        ['status' => 'D'],
                        ['student_id' => $x->school->student->id->value]
                    );
                } else {
                    $f->sql->update(
                        'sis_section_meeting_plans',
                        ['definition_hash' => str_repeat('0', 64)],
                        ['id' => $x->school->section->id->value]
                    );
                }
            });
            try {
                $x->export($audit)->prepare($f->term);
                self::fail('A changed private timetable escaped its final checks.');
            } catch (AccessDeniedException | ConcurrentModificationException | CorruptHistoryException) {
                self::assertSame($before, $x->facts());
                self::assertSame($audits, $f->sql->rows('sis_audit'));
            }
        }
    }

    public function testRepublicationPreservesOpaqueIdentityAndIncreasesTheSequenceWithoutReplacingAnOlderCopy(): void
    {
        $x = new TimetableExportFixture();
        $f = $x->school->sis;
        $first = $x->export()->prepare($f->term);
        preg_match('/^UID:(.+)$/m', str_replace("\r\n ", '', $first), $uid);
        $f->access->staff = true;
        $booking = $x->school->bookings->request(null, 'Another PRIVATE source meeting', [
            new \Domain\Scheduling\TimeSlot(
                new \DateTimeImmutable('2026-08-12T10:00:00-07:00'),
                new \DateTimeImmutable('2026-08-12T11:00:00-07:00')
            ),
        ], section: $x->school->section->id);
        $x->school->bookings->approve($booking->id, 0);
        $x->school->publish();
        $f->access->staff = false;
        $second = $x->export()->prepare($f->term);
        self::assertStringContainsString(trim($uid[1]), str_replace("\r\n ", '', $second));
        self::assertStringContainsString('SEQUENCE:1', $first);
        self::assertStringContainsString('SEQUENCE:2', $second);
    }
}
