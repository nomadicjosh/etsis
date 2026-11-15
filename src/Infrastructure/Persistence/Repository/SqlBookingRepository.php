<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Scheduling\Booking;
use Domain\Scheduling\BookingOccurrence;
use LogicException;
use Domain\Scheduling\Repository\BookingRepository;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlBookingRepository implements BookingRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function lockRoom(EntityId $id): void
    {
        $this->sql->lock('sis_rooms', $id->value, 'capacity');
    }

    public function add(Booking $booking): void
    {
        $this->sql->insert('sis_bookings', ['id' => $booking->id->value, 'room_id' => $booking->roomId->value,
            'owner_user_id' => $booking->ownerUserId->value, 'title' => $booking->title,
            'status' => $booking->status, 'version' => $booking->version]);
        foreach ($booking->occurrences as $occurrence) {
            $slot = $occurrence->time;
            $cancelledAt = $occurrence->cancelledAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $this->sql->insert('sis_booking_slots', ['id' => $occurrence->id->value,
                'booking_id' => $booking->id->value, 'room_id' => $booking->roomId->value,
                'starts_at' => $slot->startsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'ends_at' => $slot->endsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'cancelled_at' => $cancelledAt,
                'cancelled_by' => $occurrence->cancelledBy?->value]);
        }
    }

    public function get(EntityId $id): Booking
    {
        $r = $this->sql->one('sis_bookings', ['id' => $id->value]);
        $q = $this->sql->quote(...);
        $utc = new DateTimeZone('UTC');
        $occurrences = array_map(static fn (array $s): BookingOccurrence => new BookingOccurrence(
            new EntityId($s['id']),
            new TimeSlot(new DateTimeImmutable($s['starts_at'], $utc), new DateTimeImmutable($s['ends_at'], $utc)),
            $s['cancelled_by'] === null ? null : new EntityId($s['cancelled_by']),
            $s['cancelled_at'] === null ? null : new DateTimeImmutable($s['cancelled_at'], $utc),
        ), $this->sql->fetch('SELECT ' . $q('id') . ', ' . $q('starts_at') . ', ' . $q('ends_at')
            . ', ' . $q('cancelled_at') . ', ' . $q('cancelled_by') . ' FROM '
            . $q('sis_booking_slots') . ' WHERE ' . $q('booking_id') . ' = ? ORDER BY '
            . $q('starts_at') . ', ' . $q('id'), [$id->value], 367));

        return new Booking(
            $id,
            new EntityId($r['room_id']),
            new EntityId($r['owner_user_id']),
            $r['title'],
            $occurrences,
            $r['status'],
            (int) $r['version']
        );
    }

    public function save(Booking $booking, int $expectedVersion): void
    {
        if (
            $this->sql->update(
                'sis_bookings',
                ['status' => $booking->status, 'version' => $booking->version],
                ['id' => $booking->id->value, 'version' => $expectedVersion]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('The booking changed.');
        }
    }

    public function conflicts(EntityId $room, TimeSlot $slot, ?EntityId $excluding = null): bool
    {
        $q = $this->sql->quote(...);
        $utc = new DateTimeZone('UTC');
        $parameters = [$room->value, 'approved', $slot->endsAt->setTimezone($utc)->format('Y-m-d H:i:s'),
            $slot->startsAt->setTimezone($utc)->format('Y-m-d H:i:s')];
        if ($excluding !== null) {
            $parameters[] = $excluding->value;
        }

        return $this->sql->fetch('SELECT s.' . $q('id') . ' FROM ' . $q('sis_booking_slots') . ' s JOIN '
            . $q('sis_bookings') . ' b ON b.' . $q('id') . ' = s.' . $q('booking_id')
            . ' WHERE s.' . $q('cancelled_at') . ' IS NULL AND s.'
            . $q('room_id') . ' = ? AND b.' . $q('status') . ' = ? AND s.' . $q('starts_at')
            . ' < ? AND s.' . $q('ends_at') . ' > ?'
            . ($excluding === null ? '' : ' AND b.' . $q('id') . ' <> ?'), $parameters, 1) !== [];
    }

    public function cancelOccurrence(Booking $booking, EntityId $occurrence, int $expectedVersion): void
    {
        $cancelled = $booking->occurrence($occurrence);
        if ($cancelled->cancelledAt === null || $cancelled->cancelledBy === null) {
            throw new LogicException('A cancellation requires attributed meeting details.');
        }
        $this->save($booking, $expectedVersion);
        $q = $this->sql->quote(...);
        $changed = $this->sql->execute('UPDATE ' . $q('sis_booking_slots') . ' SET '
            . $q('cancelled_at') . ' = ?, ' . $q('cancelled_by') . ' = ? WHERE ' . $q('id')
            . ' = ? AND ' . $q('booking_id') . ' = ? AND ' . $q('room_id') . ' = ? AND '
            . $q('cancelled_at') . ' IS NULL', [
                $cancelled->cancelledAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $cancelled->cancelledBy->value, $occurrence->value, $booking->id->value, $booking->roomId->value,
            ]);
        if ($changed !== 1) {
            throw new ConcurrentModificationException('The meeting changed.');
        }
    }
}
