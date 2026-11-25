<?php

declare(strict_types=1);

namespace Domain\Advising\Service;

use DateTimeImmutable;
use Domain\Advising\AppointmentTime;
use Domain\Advising\ClassCalendar;
use Domain\Advising\Repository\AdvisingClassScheduleRepository;
use Domain\Registration\Service\MeetingPlanOfferingReader;
use Domain\Registration\Service\PublishedSectionMeetingPlanReader;
use Domain\Registration\ValueObject\SectionMeetingPlanDefinition;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

/** Internal current published teaching check, with original canonical people and exact offering references. */
final readonly class AdvisorTeachingCalendarService
{
    public function __construct(
        private AdvisingClassScheduleRepository $records,
        private PublishedSectionMeetingPlanReader $plans,
        private HistoricalEventStore $history,
    ) {
    }

    public function read(EntityId $person, EntityId $account, AppointmentTime $range): ClassCalendar
    {
        $budget = new HistoryReadBudget(maximumBytes: 4194304, maximumMilliseconds: 5000);
        $ids = $this->records->teaching(
            $person,
            $account,
            new DateTimeImmutable($range->startsAt)->modify('-1 day')->format('Y-m-d'),
            new DateTimeImmutable($range->endsAt)->modify('+1 day')->format('Y-m-d')
        );
        if (count($ids) > 50) {
            self::limit();
        }
        $offerings = new MeetingPlanOfferingReader($this->history);
        $sources = $meetings = [];
        $inspected = $bytes = 0;
        foreach ($ids as $id) {
            $budget->checkTime();
            $head = $this->plans->read(new EntityId($id));
            if ($head === null) {
                $sources[] = [$id, null];
                continue;
            }
            $definition = new SectionMeetingPlanDefinition($head->event->payload()['definition']);
            $bytes += strlen(json_encode($definition->data, JSON_THROW_ON_ERROR));
            $inspected += count($definition->data['meetings']);
            if ($inspected > 2000 || $bytes > 4194304) {
                self::limit();
            }
            $definitions = $offerings->read($definition, $budget);
            $sources[] = [$id, $head->event->payload()['version_id'], $head->event->playhead(),
                $definition->fingerprint()];
            foreach ($definition->data['meetings'] as $meeting) {
                $teacher = $definitions[$meeting['offering_publication_id']];
                $matches = isset($teacher['faculty_person_id'])
                ? $teacher['faculty_person_id'] === $person->value
                : $teacher['faculty_user_id'] === $account->value;
                if ($matches) {
                    $meetings[] = [$meeting['starts_at'], $meeting['ends_at']];
                }
            }
        }
        $budget->checkTime();
        sort($meetings, SORT_REGULAR);
        $hash = hash('sha256', json_encode(
            [$person->value, $account->value, $range->facts(), $sources],
            JSON_THROW_ON_ERROR
        ));
        return new ClassCalendar($range, $hash, $meetings);
    }

    public function assertCurrent(EntityId $person, EntityId $account, ClassCalendar $calendar): void
    {
        if (!$calendar->sameSources($this->read($person, $account, $calendar->range()))) {
            throw new ConcurrentModificationException(trans(
                'The advisor teaching schedule changed. Refresh available times before confirming.'
            ));
        }
    }

    private static function limit(): never
    {
        throw new ValidationException([['field' => 'start', 'message' => trans(
            'This advisor teaching schedule exceeds the bounded appointment check. '
            . 'Ask the records office to review it; no partial check was used.'
        )]]);
    }
}
