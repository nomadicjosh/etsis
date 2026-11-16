<?php

declare(strict_types=1);

namespace Domain\Scheduling\Service;

use DomainException;
use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\Booking;
use Domain\Scheduling\BookingPattern;
use Domain\Scheduling\BookingSeries;
use Domain\Scheduling\BookingUpdateKind;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Scheduling\Repository\BookingRepository;
use Domain\Scheduling\Repository\BookingCategoryRepository;
use Domain\Scheduling\Repository\BookingSectionRepository;
use Domain\Scheduling\Repository\RoomRepository;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Service\Clock;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use DateTimeZone;
use JsonException;

final readonly class BookingService
{
    public function __construct(
        private BookingRepository $repository,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private RoomRepository $rooms,
        private Clock $clock,
        private BookingCategoryRepository $categories,
        private BookingSectionRepository $sections,
    ) {
    }


    /** @param list<TimeSlot> $slots */
    public function request(
        ?EntityId $room,
        string $title,
        array $slots,
        ?EntityId $category = null,
        ?EntityId $section = null
    ): Booking {
        $actor = $this->access->authenticatedUserId();
        return $this->transactions->run(function () use ($room, $title, $slots, $category, $section, $actor): Booking {
            $this->lockRequest($room, $section, $actor);
            $this->category($category, true);
            $selected = $this->section($section, $slots, true);
            $this->location($room, $selected);
            $booking = Booking::request(
                EntityId::generate(),
                $room,
                $actor,
                $title,
                $slots,
                $category,
                $selected === null ? null : new EntityId($selected['publication_id'])
            );
            $this->repository->add($booking);
            $this->audit->record($actor, 'booking.requested', $booking->id);
            $this->actor($actor);
            if ($section !== null || $room === null) {
                $this->manager($actor);
            }
            return $booking;
        });
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
    public function review(
        ?EntityId $room,
        string $title,
        BookingPattern $pattern,
        ?EntityId $category = null,
        ?EntityId $section = null
    ): array {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $this->timezone($pattern);
        $slots = new BookingSeries()->pattern($pattern);
        $review = $this->seriesProposal($room, trim($title), $slots, $category, $section, false);
        $this->manager($actor);
        return $review + ['timezone' => $pattern->timezone->getName()];
    }

    public function requestSeries(
        EntityId $id,
        ?EntityId $room,
        string $title,
        BookingPattern $pattern,
        string $reviewedRevision,
        ?EntityId $category = null,
        ?EntityId $section = null
    ): Booking {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $slots = new BookingSeries()->pattern($pattern);
        $title = trim($title);
        return $this->transactions->run(function () use (
            $id,
            $room,
            $title,
            $slots,
            $actor,
            $pattern,
            $reviewedRevision,
            $section,
            $category
        ): Booking {
            $this->lockRequest($room, $section, $actor);
            $this->manager($actor);
            try {
                $existing = $this->repository->get($id);
            } catch (RecordNotFoundException) {
                $existing = null;
            }
            if ($existing !== null) {
                $originalSection = $existing->sectionPublicationId === null ? null
                    : $this->sections->describe($existing->sectionPublicationId);
                $proposed = Booking::request(
                    $id,
                    $room,
                    $actor,
                    $title,
                    $slots,
                    $category,
                    $existing->sectionPublicationId
                );
                if (
                    $existing->ownerUserId->value !== $actor->value
                    || $existing->roomId?->value !== $room?->value || $existing->title !== $title
                    || ($originalSection['id'] ?? null) !== $section?->value
                    || !$this->repository->matchesRequest($proposed)
                    || $this->instants($existing->times()) !== $this->instants($slots)
                ) {
                    throw new ConcurrentModificationException('That identity already belongs to another request.');
                }
                $this->manager($actor);
                return $existing;
            }
            $this->timezone($pattern);
            $review = $this->seriesProposal($room, $title, $slots, $category, $section, true);
            if (!hash_equals($review['revision'], $reviewedRevision)) {
                throw new ConcurrentModificationException('Meeting details or availability changed. Review again.');
            }
            $booking = Booking::request(
                $id,
                $room,
                $actor,
                $title,
                $slots,
                $category,
                $review['section'] === null ? null : new EntityId($review['section']['publication_id'])
            );
            $this->repository->add($booking);
            $this->audit->record($actor, 'booking.requested', $booking->id);
            $this->manager($actor);
            return $booking;
        });
    }

    private function lockRequest(?EntityId $room, ?EntityId $section, EntityId $actor): void
    {
        if ($room !== null) {
            $this->repository->lockRoom($room);
        } elseif ($section !== null) {
            $this->manager($actor);
            $this->sections->lock($section);
        } else {
            $this->manager($actor);
            $this->repository->lockRequester($actor);
        }
    }

    private function lockCurrent(Booking $booking): void
    {
        if ($booking->roomId === null) {
            $this->repository->lockBooking($booking->id);
        } else {
            $this->repository->lockRoom($booking->roomId);
        }
    }

    /** @param ?array<string,string> $selected */
    private function location(?EntityId $room, ?array $selected): void
    {
        if (
            $room === null
            && $selected !== null && !in_array($selected['delivery_mode'], ['online', 'hybrid'], true)
        ) {
            throw new ValidationException([['field' => 'room_id',
                'message' => 'This section requires a room. Online class meetings need'
                    . ' a published online or hybrid offering.']]);
        }
    }

    /** @return ?array<string,mixed> */
    private function category(?EntityId $id, bool $lock): ?array
    {
        if ($id === null) {
            return null;
        }
        if ($lock) {
            $this->categories->lock($id);
        }
        $category = $this->categories->get($id);
        if (!$category->active) {
            throw new ValidationException([['field' => 'category_id',
                'message' => 'This category is retired. Choose an active category or leave it unassigned.']]);
        }
        return $category->details();
    }

    /** @param list<TimeSlot> $slots
     * @return array<string,mixed>
     */
    private function seriesProposal(
        ?EntityId $room,
        string $title,
        array $slots,
        ?EntityId $category,
        ?EntityId $section,
        bool $lock
    ): array {
        $selected = $this->category($category, $lock);
        $offering = $this->section($section, $slots, $lock);
        $this->location($room, $offering);
        Booking::request(
            EntityId::generate(),
            $room,
            $this->access->authenticatedUserId(),
            $title,
            $slots,
            $category,
            $offering === null ? null : new EntityId($offering['publication_id'])
        );
        $result = $this->proposal($room, $title, $slots);
        $result['category'] = $selected;
        $result['section'] = $offering;
        $facts = [$result['revision'], $selected, $offering];
        $result['revision'] = hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
        return $result;
    }

    /** @return array<string,string> */
    public function sectionContext(EntityId $id): array
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $result = $this->sections->selected($id, false);
        $this->manager($actor);
        return $result;
    }

    /** @param list<TimeSlot> $slots
     * @return ?array<string,string>
     */
    private function section(?EntityId $id, array $slots, bool $lock): ?array
    {
        if ($id === null) {
            return null;
        }
        $this->access->requirePermission('sis:scheduling:manage');
        $selected = $this->sections->selected($id, $lock);
        $this->sectionDates($selected, $slots);
        return $selected;
    }

    /** @param array<string,string> $selected
     * @param list<TimeSlot> $slots
     */
    private function sectionDates(array $selected, array $slots): void
    {
        $zone = $this->clock->now()->getTimezone();
        $start = new \DateTimeImmutable($selected['instruction_starts_on'], $zone);
        $next = new \DateTimeImmutable($selected['instruction_ends_on'], new DateTimeZone('UTC'))
            ->modify('+1 day')->format('Y-m-d');
        $end = new \DateTimeImmutable($next, $zone);
        foreach ($slots as $slot) {
            if ($slot->startsAt < $start || $slot->endsAt > $end) {
                throw new ValidationException([['field' => 'section_id',
                    'message' => 'Keep class meetings within the selected offering’s instructional dates.']]);
            }
        }
    }

    public function categorize(EntityId $id, ?EntityId $category, int $version): Booking
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $initial = $this->get($id);
        return $this->transactions->run(function () use ($initial, $actor, $category, $version): Booking {
            $this->lockCurrent($initial);
            $this->manager($actor);
            $current = $this->repository->get($initial->id);
            if ($current->version !== $version || $current->roomId?->value !== $initial->roomId?->value) {
                throw new ConcurrentModificationException('Reload the booking before changing its category.');
            }
            if ($current->categoryId?->value !== $category?->value) {
                $this->category($category, true);
                $current = $current->categorize($category);
                $this->repository->save($current, $version);
                $this->audit->record($actor, 'booking.categorized', $current->id);
            }
            $this->manager($actor);
            return $current;
        });
    }

    /**
     * @param list<TimeSlot> $slots
     * @return array<string,mixed>
     * @throws JsonException
     */
    private function proposal(
        ?EntityId $room,
        string $title,
        array $slots,
        ?EntityId $excludingOccurrence = null
    ): array {
        $place = $room === null ? null : $this->rooms->get($room);
        $items = [];
        foreach ($slots as $slot) {
            $items[] = ['starts_at' => $slot->startsAt->format(DATE_ATOM),
                'ends_at' => $slot->endsAt->format(DATE_ATOM), 'local_start' => $slot->startsAt->format('Y-m-d H:i P'),
                'local_end' => $slot->endsAt->format('Y-m-d H:i P'),
                'available' => $room === null || !($excludingOccurrence === null
                    ? $this->repository->conflicts($room, $slot)
                    : $this->repository->conflictsExceptOccurrence($room, $slot, $excludingOccurrence))];
        }
        return ['room' => $place === null ? null : [
            'id' => $place->id->value, 'code' => $place->code, 'name' => $place->name,
            'building_id' => $place->buildingId?->value, 'capacity' => $place->capacity],
            'items' => $items,
            'conflicts' => count(array_filter($items, static fn(array $s): bool => !$s['available'])),
            'revision' => hash('sha256', json_encode([$room?->value, $place?->code, $place?->name,
                $place?->buildingId?->value, $place?->capacity, $title,
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
            $this->lockCurrent($initial);
            $this->manager($actor);
            $booking = $this->repository->get($id);
            if ($booking->roomId?->value !== $initial->roomId?->value) {
                throw new ConcurrentModificationException('The booking room changed.');
            }
            if ($expectedVersion !== null && $booking->version !== $expectedVersion) {
                throw new ConcurrentModificationException('Reload the booking before deciding.');
            }
            foreach ($booking->occurrences as $occurrence) {
                if (
                    $booking->roomId !== null && $occurrence->cancelledAt === null
                    && $this->repository->conflicts($booking->roomId, $occurrence->time, $booking->id)
                ) {
                    throw new DomainException('A booking occurrence conflicts with an approved booking.');
                }
            }
            $approved = $booking->transition('approved');
            $this->repository->save($approved, $booking->version);
            $this->audit->record($actor, 'booking.approved', $id);
            $this->repository->recordUpdate($approved, $actor, BookingUpdateKind::Approved, $this->clock->now());
            $this->manager($actor);

            return $approved;
        });
    }

    public function cancel(EntityId $id, ?int $expectedVersion = null): void
    {
        $booking = $this->get($id);
        $actor = $this->access->authenticatedUserId();
        $this->transactions->run(function () use ($booking, $actor, $expectedVersion): void {
            $this->lockCurrent($booking);
            $latest = $this->repository->get($booking->id);
            if ($latest->roomId?->value !== $booking->roomId?->value) {
                throw new ConcurrentModificationException('The booking room changed.');
            }
            $this->access->requireOwnerOrPermission($latest->ownerUserId, 'sis:scheduling:manage');
            if ($expectedVersion !== null && $latest->version !== $expectedVersion) {
                throw new ConcurrentModificationException('Reload the booking before cancelling.');
            }
            $cancelled = $latest->transition('cancelled');
            $this->repository->save($cancelled, $latest->version);
            $this->audit->record($actor, 'booking.cancelled', $booking->id);
            $this->repository->recordUpdate($cancelled, $actor, BookingUpdateKind::Cancelled, $this->clock->now());
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

    public function cancelOccurrence(EntityId $id, EntityId $occurrence, int $expectedVersion): Booking
    {
        $initial = $this->get($id);
        $actor = $this->access->authenticatedUserId();
        return $this->transactions->run(function () use ($initial, $actor, $occurrence, $expectedVersion): Booking {
            $this->lockCurrent($initial);
            $latest = $this->repository->get($initial->id);
            $this->actor($actor);
            $this->access->requireOwnerOrPermission($latest->ownerUserId, 'sis:scheduling:manage');
            if ($latest->roomId?->value !== $initial->roomId?->value) {
                throw new ConcurrentModificationException('The booking room changed.');
            }
            if ($latest->occurrence($occurrence)->cancelledAt !== null) {
                return $latest;
            }
            if ($latest->version !== $expectedVersion) {
                throw new ConcurrentModificationException('Reload the booking before cancelling a meeting.');
            }
            $changed = $latest->cancelOccurrence($occurrence, $actor, $this->clock->now());
            $this->repository->cancelOccurrence($changed, $occurrence, $latest->version);
            $this->audit->record($actor, 'booking.occurrence_cancelled', $latest->id);
            $this->repository->recordUpdate(
                $changed,
                $actor,
                BookingUpdateKind::MeetingCancelled,
                $this->clock->now(),
                $occurrence
            );
            $this->actor($actor);
            $this->access->requireOwnerOrPermission($latest->ownerUserId, 'sis:scheduling:manage');
            return $changed;
        });
    }

    /** @return array<string,mixed> */
    public function view(EntityId $id): array
    {
        $actor = $this->access->authenticatedUserId();
        $booking = $this->get($id);
        $links = $this->repository->links($id);
        $category = $booking->categoryId === null ? null : $this->categories->get($booking->categoryId)->details();
        $section = $booking->sectionPublicationId === null ? null
        : $this->sections->describe($booking->sectionPublicationId);
        $this->actor($actor);
        $this->access->requireOwnerOrPermission($booking->ownerUserId, 'sis:scheduling:manage');
        return ['booking' => $booking, 'category' => $category, 'section' => $section] + $links;
    }

    /** @return array<string,mixed> */
    public function reviewReplacement(
        EntityId $id,
        EntityId $occurrence,
        ?EntityId $room,
        TimeSlot $time,
        int $version
    ): array {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $booking = $this->get($id);
        $review = $this->replacementProposal($booking, $occurrence, $room, $time, $version, $actor);
        $this->manager($actor);
        return $review + ['new_id' => EntityId::generate()->value];
    }

    public function replaceOccurrence(
        EntityId $id,
        EntityId $occurrence,
        EntityId $replacementId,
        ?EntityId $room,
        TimeSlot $time,
        int $version,
        string $revision
    ): Booking {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        $initial = $this->get($id);
        return $this->transactions->run(function () use (
            $initial,
            $occurrence,
            $replacementId,
            $room,
            $time,
            $version,
            $revision,
            $actor
        ): Booking {
            $locks = [];
            foreach ([$initial->roomId, $room] as $place) {
                if ($place !== null) {
                    $locks[$place->value] = $place;
                }
            }
            ksort($locks, SORT_STRING);
            foreach ($locks as $lock) {
                $this->repository->lockRoom($lock);
            }
            if ($initial->roomId === null) {
                $this->repository->lockBooking($initial->id);
            }
            $this->manager($actor);
            $current = $this->repository->get($initial->id);
            if ($current->roomId?->value !== $initial->roomId?->value) {
                throw new ConcurrentModificationException('The booking room changed.');
            }
            $source = $current->occurrence($occurrence);
            try {
                $existing = $this->repository->get($replacementId);
            } catch (RecordNotFoundException) {
                $existing = null;
            }
            if ($existing !== null) {
                $links = $this->repository->links($existing->id);
                if (
                    $links['source_occurrence_id'] !== $occurrence->value
                    || $source->cancelledBy?->value !== $actor->value
                    || $existing->ownerUserId->value !== $current->ownerUserId->value
                    || $existing->roomId?->value !== $room?->value || $existing->title !== $current->title
                    || $this->instants($existing->times()) !== $this->instants([$time])
                ) {
                    throw new ConcurrentModificationException('That identity belongs to another reschedule request.');
                }
                $this->manager($actor);
                return $existing;
            }
            $review = $this->replacementProposal($current, $occurrence, $room, $time, $version, $actor);
            if (!hash_equals($review['revision'], $revision)) {
                throw new ConcurrentModificationException('Booking details or availability changed. Review again.');
            }
            if (!$review['available']) {
                throw new ValidationException([['field' => 'starts_at',
                    'message' => 'Choose a time without an approved booking conflict.']]);
            }
            $replacement = Booking::request(
                $replacementId,
                $room,
                $current->ownerUserId,
                $current->title,
                [$time],
                $current->categoryId,
                $current->sectionPublicationId
            );
            if ($current->status === 'approved') {
                $replacement = $replacement->transition('approved');
            }
            $changed = $current->cancelOccurrence($occurrence, $actor, $this->clock->now());
            $this->repository->replaceOccurrence($changed, $occurrence, $replacement, $current->version);
            $this->audit->record($actor, 'booking.occurrence_rescheduled', $current->id);
            $this->audit->record($actor, 'booking.replacement_created', $replacement->id);
            $this->repository->recordUpdate(
                $changed,
                $actor,
                BookingUpdateKind::Rescheduled,
                $this->clock->now(),
                $occurrence,
                $replacementId
            );
            $this->manager($actor);
            return $replacement;
        });
    }

    /** @return array<string,mixed> */
    private function replacementProposal(
        Booking $booking,
        EntityId $occurrence,
        ?EntityId $room,
        TimeSlot $time,
        int $version,
        EntityId $actor
    ): array {
        if ($booking->version !== $version) {
            throw new ConcurrentModificationException('Reload the booking before rescheduling.');
        }
        $source = $booking->occurrence($occurrence);
        $offering = $booking->sectionPublicationId === null ? null
        : $this->sections->describe($booking->sectionPublicationId);
        $this->location($room, $offering);
        if ($offering !== null) {
            $this->sectionDates($offering, [$time]);
        }
        if ($source->cancelledAt !== null || $booking->status === 'cancelled') {
            throw new ConcurrentModificationException('This meeting is no longer active. Reload the booking.');
        }
        if (
            $booking->roomId?->value === $room?->value
            && $this->instants([$source->time]) === $this->instants([$time])
        ) {
            throw new ValidationException([['field' => 'starts_at', 'message' => 'Choose a different room or time.']]);
        }
        if ($time->endsAt->getTimestamp() - $time->startsAt->getTimestamp() > 31 * 86400) {
            throw new ValidationException([['field' => 'ends_at', 'message' => 'Use a meeting of 31 days or fewer.']]);
        }
        $oldRoom = $booking->roomId === null ? null : $this->rooms->get($booking->roomId);
        $review = $this->proposal($room, $booking->title, [$time], $occurrence);
        $before = $oldRoom === null ? null : [
            'id' => $oldRoom->id->value, 'code' => $oldRoom->code, 'name' => $oldRoom->name,
            'building_id' => $oldRoom->buildingId?->value, 'capacity' => $oldRoom->capacity];
        $sourceTimes = $this->instants([$source->time])[0];
        return ['room' => $review['room'], 'source_room' => $before, 'title' => $booking->title,
            'starts_at' => $time->startsAt->format(DATE_ATOM), 'ends_at' => $time->endsAt->format(DATE_ATOM),
            'source_starts_at' => $sourceTimes[0], 'source_ends_at' => $sourceTimes[1],
            'available' => $review['items'][0]['available'], 'status' => $booking->status, 'version' => $version,
            'revision' => hash('sha256', json_encode([$actor->value, $booking->id->value, $occurrence->value,
                $version, $before, $sourceTimes, $review['revision']], JSON_THROW_ON_ERROR))];
    }
}
