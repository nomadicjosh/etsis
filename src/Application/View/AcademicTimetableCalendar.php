<?php

declare(strict_types=1);

namespace Application\View;

use Application\Service\CampusCalendarLinks;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

final readonly class AcademicTimetableCalendar
{
    public function __construct(private CampusCalendarLinks $links)
    {
    }

    public function render(EntityId $owner, #[\SensitiveParameter] array $snapshot): string
    {
        $url = $this->links->timetable();
        $rows = [];
        foreach ($snapshot['items'] as $meeting) {
            $room = $meeting['room_code'] === null ? trans('Online · No room')
            : $meeting['room_code'] . ' · ' . $meeting['room_name'];
            $building = implode(' · ', array_filter([
                $meeting['building_code'], $meeting['building_name'],
            ], static fn ($value): bool => $value !== null && $value !== ''));
            $rows[] = [
                'uid' => hash('sha256', 'academic-meeting:' . $owner->value . ':'
                    . $meeting['section_id'] . ':' . $meeting['id']),
                'version' => $meeting['plan_version'], 'updated_at' => $meeting['published_at'],
                'starts_at' => $meeting['starts_at'], 'ends_at' => $meeting['ends_at'],
                'title' => $meeting['section_code'] . ' · ' . $meeting['title'],
                'description' => trans(
                    'Published class meeting. This calendar is a snapshot. Check My timetable for updates.'
                ),
                'location' => $room . ($building === '' ? '' : ' · ' . $building), 'url' => $url,
            ];
        }
        return new CalendarDocument()->render($rows, trans('My academic timetable'), private: true);
    }
}
