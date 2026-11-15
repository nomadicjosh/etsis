<?php

declare(strict_types=1);

namespace Domain\Scheduling\Service;

use DomainException;
use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\Booking;
use Domain\Scheduling\BookingPattern;
use Domain\Scheduling\BookingSeries;
use Domain\Scheduling\Room;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Scheduling\Repository\BookingRepository;
use Domain\Scheduling\Repository\RoomRepository;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Service\Clock;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use DateTimeZone;

final readonly class BookingService
{
    public function __construct(
        private BookingRepository $repository,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private RoomRepository $rooms,
        private Clock $clock,
    ) {
    }

    public function addRoom(string $code, int $capacity): EntityId
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $id = EntityId::generate();
        $room = new Room($id, trim($code), $capacity);
        $this->transactions->run(function () use ($id, $room, $actor): void {
            $this->repository->addRoom($id, $room->code, $room->capacity);
            $this->audit->record($actor, 'room.created', $id);
            $this->manager($actor);
        });

        return $id;
    }

    /** @param list<TimeSlot> $slots */
    public function request(EntityId $room, string $title, array $slots): Booking
    {
        $actor = $this->access->authenticatedUserId();
        $booking = new Booking(EntityId::generate(), $room, $actor, $title, $slots);
        $this->transactions->run(function () use ($booking, $actor): void {
            $this->repository->lockRoom($booking->roomId);
            $this->repository->add($booking);
            $this->audit->record($actor, 'booking.requested', $booking->id);
            $this->actor($actor);
        });

        return $booking;
    }

    /** @return array{new_id:string,today:string,timezone:string} */
    public function planningContext(): array
    {
        $this->access->requirePermission('sis:scheduling:manage');
        $now = $this->clock->now();
        return ['new_id' => EntityId::generate()->value, 'today' => $now->format('Y-m-d'),
            'timezone' => $now->getTimezone()->getName()];
    }

    /** @return array<string,mixed> */
    public function review(EntityId $room, string $title, BookingPattern $pattern): array
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $this->timezone($pattern);
        $slots = new BookingSeries()->pattern($pattern);
        new Booking(EntityId::generate(), $room, $actor, trim($title), $slots);
        $review = $this->proposal($room, trim($title), $slots);
        $this->manager($actor);
        return $review + ['timezone' => $pattern->timezone->getName()];
    }

    public function requestSeries(
        EntityId $id,
        EntityId $room,
        string $title,
        BookingPattern $pattern,
        string $reviewedRevision
    ): Booking {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $booking = new Booking($id, $room, $actor, trim($title), new BookingSeries()->pattern($pattern));
        return $this->transactions->run(function () use ($booking, $actor, $pattern, $reviewedRevision): Booking {
            $this->repository->lockRoom($booking->roomId);
            $this->manager($actor);
            try {
                $existing = $this->repository->get($booking->id);

            } catch (RecordNotFoundException) {
                $existing = null;
            }
            if ($existing !== null) {
                if (
                    $existing->ownerUserId->value !== $actor->value
                    || $existing->roomId->value !== $booking->roomId->value || $existing->title !== $booking->title
                    || $this->instants($existing->slots) !== $this->instants($booking->slots)
                ) {
                    throw new ConcurrentModificationException('That identity already belongs to another request.');
                }
                $this->manager($actor);
                return $existing;
            }
            $this->timezone($pattern);
            $review = $this->proposal($booking->roomId, $booking->title, $booking->slots);
            if (!hash_equals($review['revision'], $reviewedRevision)) {
                throw new ConcurrentModificationException('Room details or availability changed. Review again.');
            }
            $this->repository->add($booking);
            $this->audit->record($actor, 'booking.requested', $booking->id);
            $this->manager($actor);
            return $booking;
        });
    }

    /** @param list<TimeSlot> $slots
     * @return array<string,mixed>
     */
    private function proposal(EntityId $room, string $title, array $slots): array
    {
        $place = $this->rooms->get($room);
        $items = [];
        foreach ($slots as $slot) {
            $items[] = ['starts_at' => $slot->startsAt->format(DATE_ATOM),
                'ends_at' => $slot->endsAt->format(DATE_ATOM), 'local_start' => $slot->startsAt->format('Y-m-d H:i P'),
                'local_end' => $slot->endsAt->format('Y-m-d H:i P'),
                'available' => !$this->repository->conflicts($room, $slot)];
        }
        return ['room' => ['id' => $room->value, 'code' => $place->code, 'capacity' => $place->capacity],
            'items' => $items,
            'conflicts' => count(array_filter($items, static fn(array $s): bool => !$s['available'])),
            'revision' => hash('sha256', json_encode([$room->value, $place->code, $place->capacity, $title,
                $this->instants($slots), array_column($items, 'available')], JSON_THROW_ON_ERROR))];
    }

    /** @param list<TimeSlot> $slots
     * @return list<array{string,string}>
     */
    private function instants(array $slots): array
    {
        $utc = new DateTimeZone('UTC');
        $result = array_map(static fn(TimeSlot $slot): array => [
            $slot->startsAt->setTimezone($utc)->format(DATE_ATOM), $slot->endsAt->setTimezone($utc)->format(DATE_ATOM),
        ], $slots);
        sort($result);
        return $result;
    }
    private function timezone(BookingPattern $pattern): void
    {
        if ($pattern->timezone->getName() !== $this->clock->now()->getTimezone()->getName()) {
            BookingPattern::invalid('timezone', 'The institution timezone changed. Reload the planner.');
        }
    }
    private function actor(EntityId $actor): void
    {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException('The booking account changed.');
        }
    }
    private function manager(EntityId $actor): void
    {
        if ($this->access->requirePermission('sis:scheduling:manage')->value !== $actor->value) {
            throw new AccessDeniedException('The scheduler changed.');
        }
        $this->actor($actor);
    }

    public function approve(EntityId $id, ?int $expectedVersion = null): Booking
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');

        $initial = $this->repository->get($id);
        return $this->transactions->run(function () use ($id, $actor, $initial, $expectedVersion): Booking {
            $this->repository->lockRoom($initial->roomId);
            $this->manager($actor);
            $booking = $this->repository->get($id);
            if ($booking->roomId->value !== $initial->roomId->value) {
                throw new ConcurrentModificationException('The booking room changed.');
            }
            if ($expectedVersion !== null && $booking->version !== $expectedVersion) {
                throw new ConcurrentModificationException('Reload the booking before deciding.');
            }
            foreach ($booking->slots as $slot) {
                if ($this->repository->conflicts($booking->roomId, $slot, $booking->id)) {
                    throw new DomainException('A booking occurrence conflicts with an approved booking.');
                }
            }
            $approved = $booking->transition('approved');
            $this->repository->save($approved, $booking->version);
            $this->audit->record($actor, 'booking.approved', $id);
            $this->manager($actor);

            return $approved;
        });
    }

    public function cancel(EntityId $id, ?int $expectedVersion = null): void
    {
        $booking = $this->get($id);
        $actor = $this->access->authenticatedUserId();
        $this->transactions->run(function () use ($booking, $actor, $expectedVersion): void {
            $this->repository->lockRoom($booking->roomId);
            $latest = $this->repository->get($booking->id);
            if ($latest->roomId->value !== $booking->roomId->value) {
                throw new ConcurrentModificationException('The booking room changed.');
            }
            $this->access->requireOwnerOrPermission($latest->ownerUserId, 'sis:scheduling:manage');
            if ($expectedVersion !== null && $latest->version !== $expectedVersion) {
                throw new ConcurrentModificationException('Reload the booking before cancelling.');
            }
            $this->repository->save($latest->transition('cancelled'), $latest->version);
            $this->audit->record($actor, 'booking.cancelled', $booking->id);
            $this->actor($actor);
            $this->access->requireOwnerOrPermission($latest->ownerUserId, 'sis:scheduling:manage');
        });
    }

    public function get(EntityId $id): Booking
    {
        $actor = $this->access->authenticatedUserId();
        $booking = $this->repository->get($id);
        $this->access->requireOwnerOrPermission($booking->ownerUserId, 'sis:scheduling:manage');
        $this->actor($actor);
        return $booking;
    }
}
