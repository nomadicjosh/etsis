<?php

declare(strict_types=1);

namespace Domain\Scheduling;

use DateTimeImmutable;
use InvalidArgumentException;

/** Transient advice for one already-authorized timetable; never a registration or reservation decision. */
final readonly class TimetableOverlapReview
{
    /** @var list<array<string,mixed>> */
    public array $items;
    /** @var array{pairs:int,meetings:int,sections:int} */
    public array $summary;
    /** @var array<string,array{summary:array{pairs:int,meetings:int,sections:int},counts:array<string,int>}> */
    public array $days;

    /** @param list<array<string,mixed>> $items */
    public function __construct(CalendarPeriod $period, array $items)
    {
        if (count($items) > 500) {
            throw new InvalidArgumentException('Overlap reviews require a complete bounded timetable.');
        }
        $bounds = $period->dayIntervals();
        $starts = array_map(static fn(TimeSlot $slot): int => $slot->startsAt->getTimestamp(), $bounds);
        $dayCounts = $daySections = array_fill(0, count($bounds), []);
        $dayPairs = array_fill(0, count($bounds), 0);
        $counts = $intervals = $sections = [];
        foreach ($items as $item) {
            if (
                !is_string($item['id']) || $item['id'] === '' || !is_string($item['section_id'])
                || $item['section_id'] === '' || isset($counts[$item['id']])
            ) {
                throw new InvalidArgumentException('Unique meeting identities and their section are required.');
            }
            $counts[$item['id']] = 0;
            $time = new TimeSlot(new DateTimeImmutable($item['starts_at']), new DateTimeImmutable($item['ends_at']));
            $start = max($time->startsAt->getTimestamp(), $period->startsAt->getTimestamp());
            $end = min($time->endsAt->getTimestamp(), $period->endsAt->getTimestamp());
            if ($start < $end) {
                $intervals[] = ['id' => $item['id'], 'section' => $item['section_id'],
                    'start' => $start, 'end' => $end];
            }
        }
        usort($intervals, static fn(array $a, array $b): int => [$a['start'], $a['id']] <=> [$b['start'], $b['id']]);
        $pairs = 0;
        $length = count($intervals);
        for ($i = 0; $i < $length; ++$i) {
            $first = $intervals[$i];
            for ($j = $i + 1; $j < $length; ++$j) {
                $second = $intervals[$j];
                // Sorted starts make ordinary nonoverlapping schedules cheap; equality is back-to-back teaching.
                if ($second['start'] >= $first['end']) {
                    break;
                }
                if ($first['section'] === $second['section']) {
                    continue;
                }
                ++$pairs;
                ++$counts[$first['id']];
                ++$counts[$second['id']];
                $sections[$first['section']] = $sections[$second['section']] = true;
                $from = self::dayAt($second['start'], $starts);
                $through = self::dayAt(min($first['end'], $second['end']) - 1, $starts);
                for ($day = $from; $day <= $through; ++$day) {
                    ++$dayPairs[$day];
                    $dayCounts[$day][$first['id']] = ($dayCounts[$day][$first['id']] ?? 0) + 1;
                    $dayCounts[$day][$second['id']] = ($dayCounts[$day][$second['id']] ?? 0) + 1;
                    $daySections[$day][$first['section']] = $daySections[$day][$second['section']] = true;
                }
            }
        }
        $this->summary = ['pairs' => $pairs, 'meetings' => count(array_filter($counts)),
            'sections' => count($sections)];
        $this->items = array_map(static function (array $item) use ($counts): array {
            $item['overlap_count'] = $counts[$item['id']];
            return $item;
        }, $items);
        $days = [];
        foreach ($bounds as $index => $slot) {
            $days[$slot->startsAt->format('Y-m-d')] = ['counts' => $dayCounts[$index],
                'summary' => ['pairs' => $dayPairs[$index], 'meetings' => count($dayCounts[$index]),
                    'sections' => count($daySections[$index])]];
        }
        $this->days = $days;
    }

    /** Greatest civil-day start at or before an already-clipped instant.
     * @param list<int> $starts
     */
    private static function dayAt(int $instant, array $starts): int
    {
        $lower = 0;
        $upper = count($starts) - 1;
        while ($lower <= $upper) {
            $middle = intdiv($lower + $upper, 2);
            if ($starts[$middle] <= $instant) {
                $lower = $middle + 1;
            } else {
                $upper = $middle - 1;
            }
        }
        return $upper;
    }
}
