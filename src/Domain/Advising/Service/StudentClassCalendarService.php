<?php

declare(strict_types=1);

namespace Domain\Advising\Service;

use DateTimeImmutable;
use Domain\Advising\AppointmentTime;
use Domain\Advising\Repository\AdvisingClassScheduleRepository;
use Domain\Advising\ClassCalendar;
use Domain\Registration\Repository\RegistrationScheduleRepository;
use Domain\Registration\Service\PublishedSectionMeetingPlanReader;
use Domain\Registration\ValueObject\SectionMeetingPlanDefinition;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

/** Internal to authorized appointment workflows; not an owner-selectable timetable endpoint. */
final readonly class StudentClassCalendarService
{
    public function __construct(
        private AdvisingClassScheduleRepository $records,
        private PublishedSectionMeetingPlanReader $plans,
        private RegistrationScheduleRepository $coordination,
    ) {
    }

    /** Match meeting-plan writers before any appointment transaction takes a snapshot read. */
    public function lock(): void
    {
        $this->coordination->lockPolicy();
    }

    public function read(EntityId $student, AppointmentTime $range): ClassCalendar
    {
        $started = hrtime(true);
        // Conservative local-date discovery covers every timezone without replaying past academic years.
        $rows = $this->records->enrollments(
            $student,
            new DateTimeImmutable($range->startsAt)->modify('-1 day')->format('Y-m-d'),
            new DateTimeImmutable($range->endsAt)->modify('+1 day')->format('Y-m-d')
        );
        if (count($rows) > 50) {
            self::limit();
        }
        $sources = $meetings = [];
        $bytes = 0;
        foreach ($rows as $row) {
            $version = filter_var($row['version'], FILTER_VALIDATE_INT);
            if ($version === false || $version < 0) {
                throw new CorruptHistoryException();
            }
            $section = new EntityId($row['section_id']);
            $head = $this->plans->read($section);
            $reference = null;
            if ($head !== null) {
                $definition = new SectionMeetingPlanDefinition($head->event->payload()['definition']);
                $bytes += strlen(json_encode($definition->data, JSON_THROW_ON_ERROR));
                if ($bytes > 4194304) {
                    self::limit();
                }
                $reference = [$head->event->payload()['version_id'], $head->event->playhead(),
                    $definition->fingerprint()];
                foreach ($definition->data['meetings'] as $meeting) {
                    $meetings[] = [$meeting['starts_at'], $meeting['ends_at']];
                    if (count($meetings) > 2000) {
                        self::limit();
                    }
                }
            }
            $sources[] = [$row, $reference];
            if (hrtime(true) - $started >= 5_000_000_000) {
                self::limit();
            }
        }
        sort($meetings, SORT_REGULAR);
        if (hrtime(true) - $started >= 5_000_000_000) {
            self::limit();
        }
        $hash = hash('sha256', json_encode([$student->value, $range->facts(), $sources], JSON_THROW_ON_ERROR));
        return new ClassCalendar($range, $hash, $meetings);
    }

    public function assertCurrent(EntityId $student, ClassCalendar $calendar): void
    {
        if (!$calendar->sameSources($this->read($student, $calendar->range()))) {
            throw new ConcurrentModificationException(trans(
                'The student class schedule changed. Refresh available times before confirming.'
            ));
        }
    }

    private static function limit(): never
    {
        throw new ValidationException([['field' => 'start', 'message' => trans(
            'This student class schedule exceeds the bounded appointment check. '
            . 'Ask the records office to review it; no partial check was used.'
        )]]);
    }
}
