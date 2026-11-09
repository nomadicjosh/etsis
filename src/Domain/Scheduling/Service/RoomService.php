<?php

declare(strict_types=1);

namespace Domain\Scheduling\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\Repository\BookingRepository;
use Domain\Scheduling\Repository\RoomRepository;
use Domain\Scheduling\Room;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class RoomService
{
    public function __construct(
        private RoomRepository $rooms,
        private BookingRepository $bookings,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @return array{id: string, code: string, capacity: int, revision: string} */
    public function view(EntityId $id): array
    {
        $this->access->requirePermission('sis:scheduling:manage');
        $room = $this->rooms->get($id);

        return ['id' => $id->value, 'code' => $room->code, 'capacity' => $room->capacity,
            'revision' => $this->revision($room)];
    }

    public function edit(EntityId $id, string $code, int $capacity, string $revision): void
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $changed = new Room($id, trim($code), $capacity);
        $this->transactions->run(function () use ($id, $changed, $revision, $actor): void {
            $this->bookings->lockRoom($id);
            if (!hash_equals($this->revision($this->rooms->get($id)), $revision)) {
                throw new ConcurrentModificationException('Reload the room before editing.');
            }
            $this->rooms->save($changed);
            $this->audit->record($actor, 'room.updated', $id);
        });
    }

    /** @return array{available: bool} */
    public function availability(EntityId $id, TimeSlot $window): array
    {
        $this->access->requirePermission('sis:scheduling:manage');
        $this->window($window);
        $this->rooms->get($id);

        return ['available' => !$this->bookings->conflicts($id, $window)];
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function agenda(EntityId $id, TimeSlot $window, ?EntityId $after = null): array
    {
        $this->access->requirePermission('sis:scheduling:manage');
        $this->window($window);
        $this->rooms->get($id);
        $rows = $this->rooms->agenda($id, $window, $after, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);

        return ['items' => $rows, 'next_after' => $more ? (string) $rows[49]['id'] : null];
    }

    private function window(TimeSlot $window): void
    {
        if ($window->endsAt->getTimestamp() - $window->startsAt->getTimestamp() > 31 * 86400) {
            throw new ValidationException([['field' => 'ends_at',
                'message' => 'Choose a window of 31 days or fewer.']]);
        }
    }

    private function revision(Room $room): string
    {
        return hash('sha256', json_encode([$room->id->value, $room->code, $room->capacity], JSON_THROW_ON_ERROR));
    }
}
