<?php

declare(strict_types=1);

namespace Application\View;

use InvalidArgumentException;

use function Codefy\Framework\Helpers\trans;

/** Presentation of already public meetings; private schedules use their own authorized adapter. */
final class PublicEventCalendar
{
    public const int MAX_BYTES = CalendarDocument::MAX_BYTES;

    public function render(array $events, string $feedUrl): string
    {
        if (count($events) > 500 || preg_match('/[\x00-\x20\x7f]/', $feedUrl)) {
            throw new InvalidArgumentException('Use a bounded public calendar and canonical URL.');
        }
        $rows = [];
        foreach ($events as $event) {
            if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $event['calendar_day'])) {
                throw new InvalidArgumentException('Use a public calendar day.');
            }
            $rows[] = array_replace($event, ['updated_at' => $event['published_at'],
                'location' => $event['room_label'] . ' · ' . $event['building_label'],
                'url' => substr($feedUrl, 0, -strlen('calendar.ics')) . '?date='
                    . $event['calendar_day'] . '&day=' . $event['calendar_day']]);
        }
        return new CalendarDocument()->render($rows, trans('Public campus events'), private: false);
    }
}
