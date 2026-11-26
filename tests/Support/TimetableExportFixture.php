<?php

declare(strict_types=1);

namespace Tests\Support;

use Application\Service\AcademicTimetableExport;
use Application\Service\CampusCalendarLinks;
use Application\View\AcademicTimetableCalendar;
use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\TimeSlot;
use Qubus\Config\Collection;

final class TimetableExportFixture
{
    public AcademicTimetableFixture $school;

    public function __construct(bool $publish = true)
    {
        require_once __DIR__ . '/AcademicTimetableFixture.php';
        $this->school = $school = new AcademicTimetableFixture();
        $booking = $school->bookings->request(null, 'PRIVATE booking purpose and conferencing credential', [
            new TimeSlot(
                new DateTimeImmutable('2026-08-10T23:30:00-07:00'),
                new DateTimeImmutable('2026-08-11T01:00:00-07:00')
            ),
        ], section: $school->section->id);
        $school->bookings->approve($booking->id, 0);
        if ($publish) {
            $school->publish();
        }
        $school->sis->access->staff = false;
        $school->sis->access->grants = [];
    }

    public function export(?AuditTrail $audit = null): AcademicTimetableExport
    {
        $f = $this->school->sis;
        $config = Collection::factory([]);
        $config->setConfigKey('app', ['url' => 'https://campus.example.invalid']);
        $config->setConfigKey('portal', ['public_event_feed' => ['enabled' => false]]);
        return new AcademicTimetableExport(
            $this->school->service(),
            new AcademicTimetableCalendar(new CampusCalendarLinks($config)),
            $f->access,
            $audit ?? $f->audit,
            $f->transactions
        );
    }

    public function facts(): array
    {
        return array_map($this->school->sis->sql->rows(...), [
            'sis_history_events', 'sis_history_streams', 'sis_history_operations',
            'sis_enrollments', 'sis_academic_credits', 'sis_bookings', 'sis_booking_slots', 'sis_section_meeting_plans',
        ]);
    }
}
