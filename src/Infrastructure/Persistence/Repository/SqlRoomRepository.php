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

        return new Room($id, $row['code'], (int) $row['capacity']);
    }

    public function save(Room $room): void
    {
        try {
            $this->sql->update(
                'sis_rooms',
                ['code' => $room->code, 'capacity' => $room->capacity],
                ['id' => $room->id->value]
            );
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new ValidationException([['field' => 'code', 'message' => 'This room code is already in use.']]);
            }
            throw $error;
        }
    }

    public function agenda(EntityId $room, TimeSlot $window, ?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        $utc = new DateTimeZone('UTC');
        $start = $window->startsAt->setTimezone($utc)->format('Y-m-d H:i:s');
        $end = $window->endsAt->setTimezone($utc)->format('Y-m-d H:i:s');
        $cursor = '';
        $parameters = [$room->value, 'requested', 'approved', $end, $start];
        if ($after !== null) {
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
            . ', s.' . $q('ends_at') . ', b.' . $q('title') . ', b.' . $q('status') . ' FROM '
            . $q('sis_booking_slots') . ' s JOIN ' . $q('sis_bookings') . ' b ON b.' . $q('id')
            . ' = s.' . $q('booking_id')
            . ' WHERE s.' . $q('room_id') . ' = ? AND b.' . $q('status') . ' IN (?, ?) AND s.' . $q('starts_at')
            . ' < ? AND s.' . $q('ends_at') . ' > ?' . $cursor . ' ORDER BY s.' . $q('starts_at') . ', s.' . $q('id'),
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
