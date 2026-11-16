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
        $item = ['id' => str_pad((string) ($i + 1), 26, '0', STR_PAD_LEFT), 'event_id' => 'fictional-public-event',
            'title' => 'Fictional open house <img src=x onerror=alert(1)>',
            'description' => "Public invitation.\nCome explore.",
            'starts_at' => '2026-08-10T18:00:00Z', 'ends_at' => '2026-08-10T19:00:00Z', 'status' => 'published',
            'timezone' => 'America/Los_Angeles', 'room_label' => 'LIB.101 · Fictional lecture room',
            'building_label' => 'LIB · Fictional library'];
        if (
            new \DateTimeImmutable($item['starts_at']) < $period->endsAt
            && new \DateTimeImmutable($item['ends_at']) > $period->startsAt
            && (($query['search'] ?? '') === '' || mb_stripos($item['title'], $query['search']) !== false)
        ) {
            $items[] = $item;
        }
    }
    return ['calendar' => $period->summarize($items, ['published']) + ['today' => '2026-08-10'],
        'calendarDate' => $query['date'] ?? '2026-08-10', 'search' => $query['search'] ?? '',
        'selectedDay' => $query['day'] ?? null, 'dailyPage' => (int) ($query['page'] ?? 0)];
}
