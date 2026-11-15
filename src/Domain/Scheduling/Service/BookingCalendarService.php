<?php

declare(strict_types=1);

namespace Domain\Scheduling\Service;

use Domain\Scheduling\BookingTimetable;
use Domain\Scheduling\Repository\BuildingRepository;
use Domain\Scheduling\Repository\RoomRepository;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class BookingCalendarService
{
    public function __construct(
        private RoomRepository $rooms,
        private BuildingRepository $buildings,
        private StudentAccess $access,
        private Clock $clock,
    ) {
    }

    /** @return array{today:string,timezone:string} */
    public function context(): array
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $now = $this->clock->now();
        $this->authorize($actor);
        return ['today' => $now->format('Y-m-d'),
            'timezone' => $now->getTimezone()->getName()];
    }

    /** @return array<string,mixed> */
    public function timetable(
        ?string $date = null,
        ?EntityId $building = null,
        string $search = '',
        string $view = 'week'
    ): array {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        if (
            !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 100
            || preg_match('/[\x00-\x1f\x7f]/', $search)
        ) {
            throw new ValidationException([['field' => 'search',
                'message' => 'Enter a search of up to 100 characters.']]);
        }
        $search = trim($search);
        $selected = $building === null ? null : $this->buildings->get($building);
        $now = $this->clock->now();
        $period = new BookingTimetable($date ?? $now->format('Y-m-d'), $now->getTimezone(), $view);
        $rows = $this->rooms->calendar(new TimeSlot($period->startsAt, $period->endsAt), $building, $search, 501);
        $this->authorize($actor);
        if (count($rows) > 500) {
            throw new ValidationException([['field' => 'search',
                'message' => 'More than 500 meetings match. Choose a building'
                    . ' or narrow your search; a week view can also reduce the results.',
            ]]);
        }
        $result = $period->summarize($rows);
        $this->authorize($actor);
        return $result + ['today' => $now->format('Y-m-d'), 'search' => $search,
            'building' => $selected === null ? null : ['id' => $selected->id->value,
                'code' => $selected->code, 'name' => $selected->name]];
    }

    private function authorize(EntityId $actor): void
    {
        if ($this->access->requirePermission('sis:scheduling:manage')->value !== $actor->value) {
            throw new AccessDeniedException('The scheduling session changed.');
        }
    }
}
