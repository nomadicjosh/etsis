<?php

declare(strict_types=1);

namespace Domain\Scheduling\Service;

use DateTimeImmutable;
use Domain\Registration\Service\PublishedSectionMeetingPlanReader;
use Domain\Registration\ValueObject\SectionMeetingPlanDefinition;
use Domain\Scheduling\CalendarPeriod;
use Domain\Scheduling\Repository\AcademicTimetableRepository;
use Domain\Scheduling\TimetableOverlapReview;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Current owner memberships and published plans only. No reservations, rosters or registration decisions. */
final readonly class AcademicTimetableService
{
    public function __construct(
        private AcademicTimetableRepository $repository,
        private PublishedSectionMeetingPlanReader $plans,
        private StudentAccess $access,
        private Clock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        $owner = $this->access->authenticatedUserId();
        $today = $this->clock->now()->format('Y-m-d');
        $term = $this->repository->recommendedTerm($owner, $today);
        $choices = $this->terms();
        $this->owner($owner);
        return ['today' => $today, 'timezone' => $this->clock->now()->getTimezone()->getName(),
            'recommended_term' => $term, 'terms' => $choices['items'], 'next_after' => $choices['next_after']];
    }

    /** @return array{items:list<array<string,mixed>>,next_after:?string} */
    public function terms(string $search = '', ?EntityId $after = null): array
    {
        $owner = $this->access->authenticatedUserId();
        if (
            !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 80
            || preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new ValidationException([['field' => 'search',
                'message' => 'Enter a term search of up to 80 characters.']]);
        }
        $rows = $this->repository->terms($owner, trim($search), $after);
        $items = array_slice($rows, 0, 20);
        $this->owner($owner);
        return ['items' => $items, 'next_after' => count($rows) > 20 ? $items[19]['id'] : null];
    }

    /** @return array<string,mixed> */
    public function timetable(EntityId $term, ?string $date = null, string $view = 'week'): array
    {
        return $this->read($term, $date, $view, false);
    }

    /** Internal export source; protected publication metadata is never placed in ordinary timetable JSON. */
    public function calendar(EntityId $term, ?string $date = null, string $view = 'week'): array
    {
        return $this->read($term, $date, $view, true);
    }

    private function read(EntityId $term, ?string $date, string $view, bool $calendar): array
    {
        $owner = $this->access->authenticatedUserId();
        $selected = $this->repository->term($owner, $term);
        $now = $this->clock->now();
        $default = $now->format('Y-m-d');
        if ($default < $selected['starts_on'] || $default > $selected['ends_on']) {
            $default = $selected['starts_on'];
        }
        $period = new CalendarPeriod($date ?? $default, $now->getTimezone(), $view);
        $source = $this->repository->sections($owner, $term);
        $rows = $classes = [];
        foreach ($source as $section) {
            $id = new EntityId($section['id']);
            $stored = $this->plans->read($id);
            $data = $stored === null ? null
            : new SectionMeetingPlanDefinition($stored->event->payload()['definition'])->data;
            if (
                $stored !== null && ($stored->event->payload()['version_id'] !== $section['publication_id']
                || !is_string($section['course_code']) || !is_string($section['course_title']))
            ) {
                throw new CorruptHistoryException();
            }
            $class = ['id' => $id->value, 'code' => $data['section_code'] ?? $section['code'],
                'course_code' => $section['course_code'], 'title' => $section['course_title'],
                'learning' => $section['learning'], 'teaching' => $section['teaching'],
                'schedule_status' => $data === null ? 'unpublished'
                    : ($data['meetings'] === [] ? 'unscheduled' : 'published')];
            $classes[] = $class;
            if ($data === null) {
                continue;
            }
            $locations = array_column($data['locations'], null, 'room_id');
            foreach ($data['meetings'] as $meeting) {
                $start = new DateTimeImmutable($meeting['starts_at']);
                $end = new DateTimeImmutable($meeting['ends_at']);
                if ($start >= $period->endsAt || $end <= $period->startsAt) {
                    continue;
                }
                $location = $meeting['room_id'] === null ? null : $locations[$meeting['room_id']];
                $rows[] = ['id' => $meeting['occurrence_id'], 'section_id' => $id->value,
                    'section_code' => $class['code'], 'course_code' => $class['course_code'],
                    'title' => $class['title'],
                    'learning' => $class['learning'], 'teaching' => $class['teaching'],
                    'starts_at' => $meeting['starts_at'], 'ends_at' => $meeting['ends_at'], 'status' => 'published',
                    'room_code' => $location['code'] ?? null, 'room_name' => $location['name'] ?? null,
                    'building_code' => $location['building_code'] ?? null,
                    'building_name' => $location['building_name'] ?? null];
                if ($calendar) {
                    $rows[array_key_last($rows)]['plan_version'] = $stored->event->playhead();
                    $rows[array_key_last($rows)]['published_at'] = $stored->recordedAt->format(DATE_ATOM);
                }
                if (count($rows) > 500) {
                    throw new ValidationException([['field' => 'date',
                        'message' => 'More than 500 meetings match. Choose a week or ask your records office for help.',
                    ]]);
                }
            }
        }
        usort($rows, static fn(array $a, array $b): int => [$a['starts_at'], $a['id']] <=> [$b['starts_at'], $b['id']]);
        $overlaps = new TimetableOverlapReview($period, $rows);
        $result = $period->summarize($overlaps->items, ['published']);
        foreach ($result['days'] as &$day) {
            $review = $overlaps->days[$day['date']];
            $day['overlaps'] = $review['summary'];
            foreach ($day['items'] as &$item) {
                $item['overlap_count'] = $review['counts'][$item['id']] ?? 0;
            }
            unset($item);
        }
        unset($day);
        // Recheck after deriving the review, before any personal result leaves this workflow.
        if (
            $source !== $this->repository->sections($owner, $term)
            || $selected !== $this->repository->term($owner, $term)
        ) {
            throw new ConcurrentModificationException(
                'Your class assignments or published schedule changed. Reload the timetable.'
            );
        }
        $this->owner($owner);
        return $result + ['overlaps' => $overlaps->summary, 'today' => $now->format('Y-m-d'),
            'term' => $selected, 'classes' => $classes];
    }

    private function owner(EntityId $expected): void
    {
        if ($this->access->authenticatedUserId()->value !== $expected->value) {
            throw new AccessDeniedException('The timetable session changed.');
        }
    }
}
