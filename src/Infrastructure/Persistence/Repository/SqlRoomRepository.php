<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Scheduling\Repository\RoomRepository;
use Domain\Scheduling\Room;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Domain\Shared\Exception\ValidationException;
use PDOException;
use InvalidArgumentException;

final readonly class SqlRoomRepository implements RoomRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function get(EntityId $id): Room
    {
        $row = $this->sql->one('sis_rooms', ['id' => $id->value]);

        return new Room(
            $id,
            $row['code'],
            (int) $row['capacity'],
            $row['name'],
            $row['building_id'] === null ? null : new EntityId($row['building_id'])
        );
    }

    public function add(Room $room): void
    {
        $this->write($room, true);
    }

    public function save(Room $room): void
    {
        $this->write($room, false);
    }

    private function write(Room $room, bool $new): void
    {
        $data = ['code' => $room->code, 'name' => $room->name,
            'building_id' => $room->buildingId?->value, 'capacity' => $room->capacity];
        try {
            if ($new) {
                $this->sql->insert('sis_rooms', ['id' => $room->id->value] + $data);
            } else {
                $this->sql->update('sis_rooms', $data, ['id' => $room->id->value]);
            }
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new ValidationException([['field' => 'code', 'message' => 'This room code is already in use.']]);
            }
            throw $error;
        }
    }

    public function agenda(EntityId $room, TimeSlot $window, ?EntityId $after, int $limit): array
    {
        return $this->meetings($window, $room, $after, null, '', $limit);
    }

    public function calendar(
        TimeSlot $window,
        ?EntityId $building,
        string $search,
        int $limit,
        ?EntityId $category = null
    ): array {
        return $this->meetings($window, null, null, $building, $search, $limit, $category);
    }

    /** @return list<array<string,mixed>> */
    private function meetings(
        TimeSlot $window,
        ?EntityId $room,
        ?EntityId $after,
        ?EntityId $building,
        string $search,
        int $limit,
        ?EntityId $category = null
    ): array {
        $q = $this->sql->quote(...);
        $utc = new DateTimeZone('UTC');
        $start = $window->startsAt->setTimezone($utc)->format('Y-m-d H:i:s');
        $end = $window->endsAt->setTimezone($utc)->format('Y-m-d H:i:s');
        $cursor = '';
        $parameters = ['requested', 'approved', $end, $start];
        $filters = '';
        if ($category !== null) {
            $filters .= ' AND b.' . $q('category_id') . ' = ?';
            $parameters[] = $category->value;
        }
        if ($room !== null) {
            $filters .= ' AND s.' . $q('room_id') . ' = ?';
            $parameters[] = $room->value;
        }
        if ($building !== null) {
            $filters .= ' AND r.' . $q('building_id') . ' = ?';
            $parameters[] = $building->value;
        }
        if ($search !== '') {
            $or = [];
            $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';
            foreach (
                ['b.title', 'r.code', 'r.name', 'building.code', 'building.name',
                'category.code', 'category.name', 'offering.code', 'offering.term_code'] as $field
            ) {
                [$alias, $column] = explode('.', $field);
                $or[] = 'LOWER(' . $alias . '.' . $q($column) . ") LIKE ? ESCAPE '!'";
                $parameters[] = $term;
            }
            $filters .= ' AND (' . implode(' OR ', $or) . ')';
        }
        if ($after !== null) {
            if ($room === null) {
                throw new InvalidArgumentException('An agenda cursor requires its room.');
            }
            $slot = $this->sql->one('sis_booking_slots', ['id' => $after->value, 'room_id' => $room->value]);
            if ($slot['starts_at'] >= $end || $slot['ends_at'] <= $start) {
                throw new InvalidArgumentException('The cursor does not belong to this window.');
            }
            $cursor = ' AND (s.' . $q('starts_at') . ' > ? OR (s.' . $q('starts_at')
            . ' = ? AND s.' . $q('id') . ' > ?))';
            array_push($parameters, $slot['starts_at'], $slot['starts_at'], $after->value);
        }
        $rows = $this->sql->fetch(
            'SELECT s.' . $q('id') . ', s.' . $q('booking_id') . ', s.' . $q('starts_at')
            . ', s.' . $q('ends_at') . ', b.' . $q('title') . ', b.' . $q('status')
            . ', s.' . $q('room_id') . ', r.' . $q('code') . ' AS ' . $q('room_code')
            . ', r.' . $q('name') . ' AS ' . $q('room_name') . ', r.' . $q('building_id')
            . ', building.' . $q('code') . ' AS ' . $q('building_code')
            . ', building.' . $q('name') . ' AS ' . $q('building_name')
            . ', b.' . $q('category_id') . ', category.' . $q('name') . ' AS ' . $q('category_name')
            . ', category.' . $q('tone') . ' AS ' . $q('category_tone')
            . ', b.' . $q('section_publication_id') . ', offering.' . $q('section_id')
            . ', offering.' . $q('code') . ' AS ' . $q('section_code')
            . ', offering.' . $q('term_code') . ' AS ' . $q('section_term_code') . ' FROM '
            // Category and offering labels are joined once; calendar rows never replay each section.
            . $q('sis_booking_slots') . ' s JOIN ' . $q('sis_bookings') . ' b ON b.' . $q('id')
            . ' = s.' . $q('booking_id')
            . ' JOIN ' . $q('sis_rooms') . ' r ON r.' . $q('id') . ' = s.' . $q('room_id')
            . ' LEFT JOIN ' . $q('sis_buildings') . ' building ON building.' . $q('id') . ' = r.' . $q('building_id')
            . ' LEFT JOIN ' . $q('sis_booking_categories') . ' category ON category.' . $q('id')
            . ' = b.' . $q('category_id')
            . ' LEFT JOIN ' . $q('sis_section_versions') . ' offering ON offering.' . $q('id')
            . ' = b.' . $q('section_publication_id')
            . ' WHERE s.' . $q('cancelled_at') . ' IS NULL AND b.' . $q('status')
            . ' IN (?, ?) AND s.' . $q('starts_at')
            . ' < ? AND s.' . $q('ends_at') . ' > ?' . $filters . $cursor
            . ' ORDER BY s.' . $q('starts_at') . ', s.' . $q('id'),
            $parameters,
            $limit
        );
        foreach ($rows as &$row) {
            $row['starts_at'] = new DateTimeImmutable($row['starts_at'], $utc)->format(DATE_ATOM);
            $row['ends_at'] = new DateTimeImmutable($row['ends_at'], $utc)->format(DATE_ATOM);
        }
        unset($row);

        return $rows;
    }
}
