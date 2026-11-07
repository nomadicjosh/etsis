<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Scheduling\Booking;
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

    public function addRoom(EntityId $id, string $code, int $capacity): void
    {
        $this->sql->insert('sis_rooms', ['id' => $id->value, 'code' => $code, 'capacity' => $capacity]);
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
        foreach ($booking->slots as $slot) {
            $this->sql->insert('sis_booking_slots', ['id' => EntityId::generate()->value,
                'booking_id' => $booking->id->value, 'room_id' => $booking->roomId->value,
                'starts_at' => $slot->startsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'ends_at' => $slot->endsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
        }
    }

    public function get(EntityId $id): Booking
    {
        $r = $this->sql->one('sis_bookings', ['id' => $id->value]);
        $slots = array_map(static fn (array $s): TimeSlot => new TimeSlot(
            new DateTimeImmutable($s['starts_at'], new DateTimeZone('UTC')),
            new DateTimeImmutable($s['ends_at'], new DateTimeZone('UTC')),
        ), $this->sql->rows('sis_booking_slots', ['booking_id' => $id->value]));

        return new Booking(
            $id,
            new EntityId($r['room_id']),
            new EntityId($r['owner_user_id']),
            $r['title'],
            $slots,
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
            . ' WHERE s.' . $q('room_id') . ' = ? AND b.' . $q('status') . ' = ? AND s.' . $q('starts_at')
            . ' < ? AND s.' . $q('ends_at') . ' > ?'
            . ($excluding === null ? '' : ' AND b.' . $q('id') . ' <> ?'), $parameters, 1) !== [];
    }
}
