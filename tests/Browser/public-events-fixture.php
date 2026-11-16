<?php

declare(strict_types=1);

/** Fictional public rendering only; no bootstrap, account, institution database or credentials. */
function publicEventsFixture(array $query): array
{
    $period = new \Domain\Scheduling\CalendarPeriod(
        $query['date'] ?? '2026-08-10',
        new \DateTimeZone('America/Los_Angeles'),
        $query['view'] ?? 'month'
    );
    $items = [];
    for ($i = 0; $i < 21; ++$i) {
        $items[] = ['id' => str_pad((string) ($i + 1), 26, '0', STR_PAD_LEFT), 'event_id' => 'fictional-public-event',
            'title' => 'Fictional open house <img src=x onerror=alert(1)>',
            'description' => "Public invitation.\nCome explore.",
            'starts_at' => '2026-08-10T18:00:00Z', 'ends_at' => '2026-08-10T19:00:00Z', 'status' => 'published',
            'timezone' => 'America/Los_Angeles', 'room_label' => 'LIB.101 · Fictional lecture room',
            'building_label' => 'LIB · Fictional library'];
    }
    $items[] = ['id' => str_repeat('9', 26), 'event_id' => 'fictional-online-event',
        'title' => 'Fictional online gathering', 'description' => 'Ask the organizer for joining details.',
        'starts_at' => '2026-08-12T18:00:00Z', 'ends_at' => '2026-08-12T19:00:00Z', 'status' => 'published',
        'timezone' => 'America/Los_Angeles', 'room_label' => 'Online event',
        'building_label' => 'Joining details provided by the organizer'];
    $items = array_values(array_filter($items, static fn(array $item): bool =>
        new \DateTimeImmutable($item['starts_at']) < $period->endsAt
        && new \DateTimeImmutable($item['ends_at']) > $period->startsAt
        && (($query['search'] ?? '') === '' || mb_stripos(implode(' ', [
            $item['title'], $item['room_label'], $item['building_label'],
        ]), $query['search']) !== false)));
    return ['calendar' => $period->summarize($items, ['published']) + ['today' => '2026-08-10'],
        'calendarDate' => $query['date'] ?? '2026-08-10', 'search' => $query['search'] ?? '',
        'selectedDay' => $query['day'] ?? null, 'dailyPage' => (int) ($query['page'] ?? 0)];
}
