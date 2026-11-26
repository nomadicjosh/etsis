<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Scheduling\Repository\PublicEventRepository;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlPublicEventRepository implements PublicEventRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function source(EntityId $booking): array
    {
        $q = $this->sql->quote(...);
        $row = $this->sql->one('sis_bookings', ['id' => $booking->value]);
        if ($row['status'] !== 'approved' || $row['section_publication_id'] !== null) {
            throw new ValidationException([['field' => 'booking',
                'message' => 'Choose an approved standalone booking without a course-section link.']]);
        }
        $room = $row['room_id'] === null ? null : $this->sql->one('sis_rooms', ['id' => $row['room_id']]);
        $building = ($room['building_id'] ?? null) === null ? null
        : $this->sql->one('sis_buildings', ['id' => $room['building_id']]);
        $times = $this->sql->fetch('SELECT ' . $q('id') . ' AS ' . $q('occurrence_id') . ', '
            . $q('starts_at') . ', ' . $q('ends_at') . ', ' . $q('room_id') . ' FROM ' . $q('sis_booking_slots')
            . ' WHERE ' . $q('booking_id') . ' = ? AND ' . $q('cancelled_at') . ' IS NULL ORDER BY '
            . $q('starts_at') . ', ' . $q('id'), [$booking->value], 367);
        if ($times === [] || count($times) > 366) {
            throw new ValidationException([['field' => 'booking',
                'message' => 'Publish a booking with between one and 366 active meetings.']]);
        }
        foreach ($times as &$time) {
            if ($time['room_id'] !== $row['room_id']) {
                throw new ValidationException([['field' => 'booking',
                    'message' => 'A meeting location does not match its booking. Review the booking first.']]);
            }
            unset($time['room_id']);
        }
        unset($time);
        // Deliberately exclude the booking title, owner, internal category and academic references.
        return ['booking_version' => (int) $row['version'], 'room_id' => $room['id'] ?? null,
            'building_id' => $building['id'] ?? null,
            'room_label' => $room === null ? 'Online event' : $room['code'] . ' · ' . $room['name'],
            'building_label' => $room === null ? 'Joining details provided by the organizer'
                : ($building === null ? 'Building not assigned' : $building['code'] . ' · ' . $building['name']),
            'times' => $times];
    }

    public function lock(EntityId $booking, array $source): void
    {
        // Same order as room booking and meeting-plan decisions.
        if ($source['room_id'] !== null) {
            $this->sql->lock('sis_rooms', $source['room_id'], 'capacity');
        }
        $this->sql->lock('sis_bookings', $booking->value, 'version');
        if ($source['building_id'] !== null) {
            $this->sql->lock('sis_buildings', $source['building_id'], 'code');
        }
    }

    public function current(EntityId $booking): ?array
    {
        return $this->sql->rows('sis_public_events', ['booking_id' => $booking->value], 1)[0] ?? null;
    }

    public function publish(array $record, array $times, int $expectedVersion): void
    {
        if ($expectedVersion === 0) {
            $this->sql->insert('sis_public_events', $record);
        } elseif (
            $this->sql->update(
                'sis_public_events',
                $record,
                ['id' => $record['id'], 'version' => $expectedVersion]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('Review the public event again.');
        }
        $this->sql->execute('DELETE FROM ' . $this->sql->quote('sis_public_event_times')
            . ' WHERE ' . $this->sql->quote('event_id') . ' = ?', [$record['id']]);
        foreach ($times as $time) {
            $this->sql->insert('sis_public_event_times', ['id' => EntityId::generate()->value,
                'event_id' => $record['id']] + $time);
        }
    }

    public function withdraw(EntityId $booking, int $expectedVersion): void
    {
        if (
            $this->sql->update(
                'sis_public_events',
                ['published' => 0, 'version' => $expectedVersion + 1],
                ['booking_id' => $booking->value, 'version' => $expectedVersion]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('Reload the public event before withdrawing it.');
        }
    }

    public function calendar(TimeSlot $window, string $search, int $limit): array
    {
        $q = $this->sql->quote(...);
        $query = $this->publishedSelect() . ' AND p.' . $q('starts_at') . ' < ? AND p.' . $q('ends_at') . ' > ?';
        $utc = new \DateTimeZone('UTC');
        $params = ['approved', $window->endsAt->setTimezone($utc)->format('Y-m-d H:i:s'),
            $window->startsAt->setTimezone($utc)->format('Y-m-d H:i:s')];
        if ($search !== '') {
            $literal = '%' . strtr(mb_strtolower($search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $parts = [];
            foreach (['title', 'room_label', 'building_label'] as $field) {
                $parts[] = 'LOWER(e.' . $q($field) . ") LIKE ? ESCAPE '!'";
                $params[] = $literal;
            }
            $query .= ' AND (' . implode(' OR ', $parts) . ')';
        }
        return $this->publicRows($query, $params, $limit);
    }

    public function publishedTimes(EntityId $booking): array
    {
        return $this->publicRows(
            $this->publishedSelect() . ' AND e.' . $this->sql->quote('booking_id') . ' = ?',
            ['approved', $booking->value],
            367
        );
    }

    public function calendarFeed(TimeSlot $window, int $limit): array
    {
        $q = $this->sql->quote(...);
        $utc = new \DateTimeZone('UTC');
        return $this->publicRows(
            $this->publishedSelect(true) . ' AND p.' . $q('starts_at') . ' < ? AND p.' . $q('ends_at') . ' > ?',
            ['approved', $window->endsAt->setTimezone($utc)->format('Y-m-d H:i:s'),
                $window->startsAt->setTimezone($utc)->format('Y-m-d H:i:s')],
            $limit,
            true,
        );
    }

    private function publishedSelect(bool $feed = false): string
    {
        $q = $this->sql->quote(...);
        $columns = ['p.id', 'p.event_id', 'p.starts_at', 'p.ends_at', 'e.title', 'e.description',
            'e.timezone', 'e.room_label', 'e.building_label'];
        if ($feed) {
            array_push($columns, 'p.occurrence_id', 'e.version', 'e.published_at');
        }
        $columns = array_map(static function (string $column) use ($q): string {
            [$alias, $field] = explode('.', $column);
            return $alias . '.' . $q($field);
        }, $columns);
        return 'SELECT ' . implode(', ', $columns) . ' FROM ' . $q('sis_public_event_times') . ' p JOIN '
        . $q('sis_public_events') . ' e ON e.' . $q('id') . ' = p.' . $q('event_id') . ' JOIN '
        . $q('sis_bookings') . ' b ON b.' . $q('id') . ' = e.' . $q('booking_id') . ' AND (b.'
        . $q('room_id') . ' = e.' . $q('room_id') . ' OR b.' . $q('room_id')
        . ' IS NULL AND e.' . $q('room_id') . ' IS NULL) JOIN ' . $q('sis_booking_slots') . ' s ON s.'
        . $q('id') . ' = p.' . $q('occurrence_id') . ' AND s.' . $q('booking_id') . ' = b.' . $q('id')
        . ' AND s.' . $q('starts_at') . ' = p.' . $q('starts_at') . ' AND s.' . $q('ends_at')
        . ' = p.' . $q('ends_at') . ' AND (s.' . $q('room_id') . ' = e.' . $q('room_id')
        . ' OR s.' . $q('room_id') . ' IS NULL AND e.' . $q('room_id') . ' IS NULL)'
        . ' WHERE e.' . $q('published') . ' = 1 AND b.' . $q('status')
        . ' = ? AND b.' . $q('section_publication_id') . ' IS NULL AND s.' . $q('cancelled_at')
        . ' IS NULL';
    }

    /** @param list<string> $params
     * @return list<array<string,mixed>>
     */
    private function publicRows(string $query, array $params, int $limit, bool $feed = false): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch($query . ' ORDER BY p.' . $q('starts_at') . ', p.' . $q('id'), $params, $limit);
        return array_map(static function (array $row) use ($feed): array {
            foreach (['starts_at', 'ends_at'] as $field) {
                $row[$field] = str_replace(' ', 'T', $row[$field]) . 'Z';
            }
            if ($feed) {
                $row['uid'] = hash('sha256', $row['event_id'] . ':' . $row['occurrence_id']);
                $row['version'] = (int) $row['version'];
                return array_intersect_key($row, array_flip([
                    'uid', 'version', 'published_at', 'starts_at', 'ends_at',
                    'title', 'description', 'room_label', 'building_label',
                ]));
            }
            return $row + ['status' => 'published'];
        }, $rows);
    }
}
