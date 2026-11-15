<?php

declare(strict_types=1);

namespace Domain\Scheduling\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\Repository\BookingRepository;
use Domain\Scheduling\Repository\BuildingRepository;
use Domain\Scheduling\Repository\RoomRepository;
use Domain\Scheduling\Room;
use Domain\Scheduling\RoomTimetable;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use JsonException;

final readonly class RoomService
{
    public function __construct(
        private RoomRepository $rooms,
        private BookingRepository $bookings,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private BuildingRepository $buildings,
    ) {
    }

    /**
     * @return array<string,mixed>
     * @throws JsonException
     */
    public function view(EntityId $id): array
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $room = $this->rooms->get($id);
        $data = $this->details($room) + ['revision' => $this->revision($room)];
        $this->authorize($actor);
        return $data;
    }

    public function create(string $code, string $name, EntityId $building, int $capacity): EntityId
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $room = new Room(EntityId::generate(), trim($code), $capacity, trim($name), $building);
        $this->transactions->run(function () use ($actor, $room, $building): void {
            $this->buildings->lock($building);
            $this->buildings->get($building);
            $this->authorize($actor);
            $this->rooms->add($room);
            $this->audit->record($actor, 'room.created', $room->id);
            $this->authorize($actor);
        });
        return $room->id;
    }

    public function edit(
        EntityId $id,
        string $code,
        string $name,
        EntityId $building,
        int $capacity,
        string $revision
    ): void {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $changed = new Room($id, trim($code), $capacity, trim($name), $building);
        $this->transactions->run(function () use ($id, $changed, $revision, $actor, $building): void {
            $this->buildings->lock($building);
            $this->buildings->get($building);
            $this->bookings->lockRoom($id);
            $this->authorize($actor);
            if (!hash_equals($this->revision($this->rooms->get($id)), $revision)) {
                throw new ConcurrentModificationException('Reload the room before editing.');
            }
            $this->rooms->save($changed);
            $this->audit->record($actor, 'room.updated', $id);
            $this->authorize($actor);
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

    /** @return array<string,mixed> */
    public function week(EntityId $id, ?string $date = null): array
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $now = $this->clock->now();
        $room = $this->rooms->get($id);
        $week = new RoomTimetable($date ?? $now->format('Y-m-d'), $now->getTimezone());
        $rows = $this->rooms->agenda($id, new TimeSlot($week->startsAt, $week->endsAt), null, 501);
        if (count($rows) > 500) {
            throw new ValidationException([['field' => 'week',
                'message' => 'This week exceeds 500 occurrences. Use the paged room agenda for a narrower window.']]);
        }
        $result = $week->summarize($rows);
        if ($this->access->requirePermission('sis:scheduling:manage')->value !== $actor->value) {
            throw new AccessDeniedException('The scheduling session changed.');
        }

        $data = $result + ['today' => $now->format('Y-m-d'), 'room' => $this->details($room)];
        $this->authorize($actor);
        return $data;
    }

    private function window(TimeSlot $window): void
    {
        if ($window->endsAt->getTimestamp() - $window->startsAt->getTimestamp() > 31 * 86400) {
            throw new ValidationException([['field' => 'ends_at',
                'message' => 'Choose a window of 31 days or fewer.']]);
        }
    }

    /**
     * @throws JsonException
     */
    private function revision(Room $room): string
    {
        return hash('sha256', json_encode([$room->id->value, $room->code, $room->name,
            $room->buildingId?->value, $room->capacity], JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function details(Room $room): array
    {
        $building = $room->buildingId === null ? null : $this->buildings->get($room->buildingId);
        return ['id' => $room->id->value, 'code' => $room->code, 'name' => $room->name,
            'capacity' => $room->capacity, 'building_id' => $room->buildingId?->value,
            'building' => $building === null ? null : ['id' => $building->id->value,
                'code' => $building->code, 'name' => $building->name]];
    }

    private function authorize(EntityId $actor): void
    {
        if ($this->access->requirePermission('sis:scheduling:manage')->value !== $actor->value) {
            throw new AccessDeniedException('The scheduling session changed.');
        }
    }
}
