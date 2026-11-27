<?php

declare(strict_types=1);

namespace Application\View;

use Application\Service\CampusCalendarLinks;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

final readonly class AdvisingAppointmentCalendar
{
    public function __construct(private CampusCalendarLinks $links)
    {
    }

    public function render(EntityId $owner, #[\SensitiveParameter] array $snapshot): string
    {
        $url = $this->links->timetable();
        $events = [];
        foreach ($snapshot['items'] as $item) {
            $events[] = [
                'uid' => hash('sha256', 'advising-appointment:' . $owner->value . ':' . $item['id']),
                'version' => $item['version'] + 1,
                'updated_at' => $item['updated_at'],
                'starts_at' => $item['starts_at'],
                'ends_at' => $item['ends_at'],
                'title' => trans('Advising appointment'),
                'description' => trans('This calendar is a snapshot. Check My timetable for appointment changes.'),
                'location' => '',
                'url' => $url,
                'status' => $item['status'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED',
            ];
        }
        return new CalendarDocument()->render($events, trans('My advising appointments'), private: true);
    }
}
