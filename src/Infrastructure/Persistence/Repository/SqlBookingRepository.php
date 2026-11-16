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

    public function lockBooking(EntityId $id): void
    {
        $this->sql->lock('sis_bookings', $id->value, 'version');
    }

    public function lockRequester(EntityId $owner): void
    {
        $this->sql->lock('users', $owner->value, 'user_id', 'user_id');
    }

    public function add(Booking $booking): void
    {
        $this->sql->insert('sis_bookings', ['id' => $booking->id->value, 'room_id' => $booking->roomId?->value,
            'owner_user_id' => $booking->ownerUserId->value, 'title' => $booking->title,
            'status' => $booking->status, 'version' => $booking->version,
            'category_id' => $booking->categoryId?->value,
            'section_publication_id' => $booking->sectionPublicationId?->value,
            'request_hash' => $booking->requestFingerprint()]);
        foreach ($booking->occurrences as $occurrence) {
            $slot = $occurrence->time;
            $cancelledAt = $occurrence->cancelledAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $this->sql->insert('sis_booking_slots', ['id' => $occurrence->id->value,
                'booking_id' => $booking->id->value, 'room_id' => $booking->roomId?->value,
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
            $r['room_id'] === null ? null : new EntityId($r['room_id']),
            new EntityId($r['owner_user_id']),
            $r['title'],
            $occurrences,
            $r['status'],
            (int) $r['version'],
            $r['category_id'] === null ? null : new EntityId($r['category_id']),
            $r['section_publication_id'] === null ? null : new EntityId($r['section_publication_id'])
        );
    }

    public function matchesRequest(Booking $request): bool
    {
        $row = $this->sql->one('sis_bookings', ['id' => $request->id->value]);
        return is_string($row['request_hash']) && hash_equals($row['request_hash'], $request->requestFingerprint());
    }

    public function save(Booking $booking, int $expectedVersion): void
    {
        if (
            $this->sql->update(
                'sis_bookings',
                ['status' => $booking->status, 'version' => $booking->version,
                    'category_id' => $booking->categoryId?->value],
                ['id' => $booking->id->value, 'version' => $expectedVersion]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('The booking changed.');
        }
    }

    public function conflicts(EntityId $room, TimeSlot $slot, ?EntityId $excluding = null): bool
    {
        return $this->overlaps($room, $slot, $excluding, null);
    }

    public function conflictsExceptOccurrence(EntityId $room, TimeSlot $slot, EntityId $excluding): bool
    {
        return $this->overlaps($room, $slot, null, $excluding);
    }

    private function overlaps(EntityId $room, TimeSlot $slot, ?EntityId $booking, ?EntityId $occurrence): bool
    {
        $q = $this->sql->quote(...);
        $utc = new DateTimeZone('UTC');
        $parameters = [$room->value, 'approved', $slot->endsAt->setTimezone($utc)->format('Y-m-d H:i:s'),
            $slot->startsAt->setTimezone($utc)->format('Y-m-d H:i:s')];
        $excluded = $booking ?? $occurrence;
        if ($excluded !== null) {
            $parameters[] = $excluded->value;
        }

        return $this->sql->fetch('SELECT s.' . $q('id') . ' FROM ' . $q('sis_booking_slots') . ' s JOIN '
            . $q('sis_bookings') . ' b ON b.' . $q('id') . ' = s.' . $q('booking_id')
            . ' WHERE s.' . $q('cancelled_at') . ' IS NULL AND s.'
            . $q('room_id') . ' = ? AND b.' . $q('status') . ' = ? AND s.' . $q('starts_at')
            . ' < ? AND s.' . $q('ends_at') . ' > ?'
            . ($booking !== null ? ' AND b.' . $q('id') . ' <> ?'
                : ($occurrence !== null ? ' AND s.' . $q('id') . ' <> ?' : '')), $parameters, 1) !== [];
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
            . ' = ? AND ' . $q('booking_id') . ' = ? AND ' . $q('room_id')
            . ($booking->roomId === null ? ' IS NULL AND ' : ' = ? AND ')
            . $q('cancelled_at') . ' IS NULL', [
                $cancelled->cancelledAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $cancelled->cancelledBy->value, $occurrence->value, $booking->id->value,
                ...($booking->roomId === null ? [] : [$booking->roomId->value]),
            ]);
        if ($changed !== 1) {
            throw new ConcurrentModificationException('The meeting changed.');
        }
    }

    public function replaceOccurrence(
        Booking $original,
        EntityId $occurrence,
        Booking $replacement,
        int $expectedVersion
    ): void {
        $this->cancelOccurrence($original, $occurrence, $expectedVersion);
        $this->add($replacement);
        $this->sql->insert('sis_booking_replacements', [
            'id' => $occurrence->value, 'booking_id' => $replacement->id->value,
        ]);
    }

    public function links(EntityId $booking): array
    {
        $q = $this->sql->quote(...);
        $from = ' FROM ' . $q('sis_booking_replacements') . ' r JOIN ' . $q('sis_booking_slots')
        . ' s ON s.' . $q('id') . ' = r.' . $q('id');
        $source = $this->sql->fetch('SELECT s.' . $q('booking_id') . ', r.' . $q('id') . $from
            . ' WHERE r.' . $q('booking_id') . ' = ?', [$booking->value], 1);
        $rows = $this->sql->fetch('SELECT r.' . $q('id') . ', r.' . $q('booking_id') . $from
            . ' WHERE s.' . $q('booking_id') . ' = ?', [$booking->value], 367);
        if (count($rows) > 366) {
            throw new LogicException('A booking has too many replacement references.');
        }
        $replacements = [];
        foreach ($rows as $row) {
            $replacements[$row['id']] = $row['booking_id'];
        }
        return ['source_booking_id' => $source[0]['booking_id'] ?? null,
            'source_occurrence_id' => $source[0]['id'] ?? null, 'replacements' => $replacements];
    }
}
