<?php

declare(strict_types=1);

namespace Domain\Scheduling\Service;

use DomainException;
use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\Booking;
use Domain\Scheduling\Repository\BookingRepository;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class BookingService
{
    public function __construct(
        private BookingRepository $repository,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    public function addRoom(string $code, int $capacity): EntityId
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        if (trim($code) === '' || strlen($code) > 80 || $capacity < 1 || $capacity > 100000) {
            throw new InvalidArgumentException('A room requires a code and positive capacity.');
        }
        $id = EntityId::generate();
        $this->transactions->run(function () use ($id, $code, $capacity, $actor): void {
            $this->repository->addRoom($id, $code, $capacity);
            $this->audit->record($actor, 'room.created', $id);
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
        });

        return $booking;
    }

    public function approve(EntityId $id): Booking
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');

        $initial = $this->repository->get($id);
        return $this->transactions->run(function () use ($id, $actor, $initial): Booking {
            $this->repository->lockRoom($initial->roomId);
            $booking = $this->repository->get($id);
            foreach ($booking->slots as $slot) {
                if ($this->repository->conflicts($booking->roomId, $slot, $booking->id)) {
                    throw new DomainException('A booking occurrence conflicts with an approved booking.');
                }
            }
            $approved = $booking->transition('approved');
            $this->repository->save($approved, $booking->version);
            $this->audit->record($actor, 'booking.approved', $id);

            return $approved;
        });
    }

    public function cancel(EntityId $id): void
    {
        $booking = $this->get($id);
        $actor = $this->access->authenticatedUserId();
        $this->transactions->run(function () use ($booking, $actor): void {
            $this->repository->lockRoom($booking->roomId);
            $latest = $this->repository->get($booking->id);
            $this->repository->save($latest->transition('cancelled'), $latest->version);
            $this->audit->record($actor, 'booking.cancelled', $booking->id);
        });
    }

    public function get(EntityId $id): Booking
    {
        $this->access->authenticatedUserId();
        $booking = $this->repository->get($id);
        $this->access->requireOwnerOrPermission($booking->ownerUserId, 'sis:scheduling:manage');

        return $booking;
    }
}
