<?php

declare(strict_types=1);

namespace Domain\Scheduling\Repository;

use DateTimeImmutable;
use Domain\Scheduling\Booking;
use Domain\Scheduling\BookingUpdateKind;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\ValueObject\EntityId;

interface BookingRepository
{
    public function lockRoom(EntityId $id): void;
    public function lockBooking(EntityId $id): void;
    /** Serialize standalone online request identities for this authenticated requester. */
    public function lockRequester(EntityId $owner): void;
    public function add(Booking $booking): void;
    public function get(EntityId $id): Booking;
    public function matchesRequest(Booking $request): bool;
    public function save(Booking $booking, int $expectedVersion): void;
    public function cancelOccurrence(Booking $booking, EntityId $occurrence, int $expectedVersion): void;
    public function replaceOccurrence(
        Booking $original,
        EntityId $occurrence,
        Booking $replacement,
        int $expectedVersion
    ): void;
    /** @return array{source_booking_id:?string,source_occurrence_id:?string,replacements:array<string,string>} */
    public function links(EntityId $booking): array;
    public function conflictsExceptOccurrence(EntityId $room, TimeSlot $slot, EntityId $excluding): bool;
    public function conflicts(EntityId $room, TimeSlot $slot, ?EntityId $excluding = null): bool;
    /** The caller has saved this booking revision inside the same transaction. */
    public function recordUpdate(
        Booking $booking,
        EntityId $actor,
        BookingUpdateKind $kind,
        DateTimeImmutable $at,
        ?EntityId $occurrence = null,
        ?EntityId $related = null
    ): void;
    /** @return list<array<string,mixed>> One owner-scoped page and a continuation probe. */
    public function ownerUpdates(EntityId $owner, bool $unreadOnly, ?string $afterAt, ?EntityId $after): array;
    /** @return array<string,mixed> */
    public function ownerUpdate(EntityId $owner, EntityId $id): array;
    public function unreadUpdates(EntityId $owner): int;
    /** Return false for an already accepted acknowledgement of the same notice revision. */
    public function acknowledgeUpdate(EntityId $owner, EntityId $id, int $noticeVersion): bool;
}
