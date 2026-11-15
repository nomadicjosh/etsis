<?php

declare(strict_types=1);

namespace Domain\Scheduling;

use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\RecordNotFoundException;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;

final readonly class Booking
{
    /** @param list<BookingOccurrence> $occurrences */
    public function __construct(
        public EntityId $id,
        public ?EntityId $roomId,
        public EntityId $ownerUserId,
        public string $title,
        public array $occurrences,
        public string $status = 'requested',
        public int $version = 0,
        public ?EntityId $categoryId = null,
        public ?EntityId $sectionPublicationId = null,
    ) {
        if (
            trim($title) === '' || !mb_check_encoding($title, 'UTF-8') || mb_strlen($title) > 191
            || preg_match('/[\x00-\x1f\x7f]/', $title) || count($occurrences) < 1 || count($occurrences) > 366
            || !in_array($status, ['requested', 'approved', 'cancelled'], true)
            || ($roomId === null && $sectionPublicationId === null)
        ) {
            throw new InvalidArgumentException('A booking requires a title, bounded occurrences and a valid state.');
        }
        $ids = [];
        foreach ($occurrences as $i => $occurrence) {
            if (isset($ids[$occurrence->id->value])) {
                throw new InvalidArgumentException('Meeting identities must be unique within a booking.');
            }
            $ids[$occurrence->id->value] = true;
            foreach (array_slice($occurrences, $i + 1) as $other) {
                if ($occurrence->time->overlaps($other->time)) {
                    throw new DomainException('Booking occurrences cannot overlap each other.');
                }
            }
        }
    }

    /** @param list<TimeSlot> $times */
    public static function request(
        EntityId $id,
        ?EntityId $room,
        EntityId $owner,
        string $title,
        array $times,
        ?EntityId $category = null,
        ?EntityId $sectionPublication = null
    ): self {
        return new self(
            $id,
            $room,
            $owner,
            $title,
            array_map(BookingOccurrence::plan(...), $times),
            categoryId: $category,
            sectionPublicationId: $sectionPublication
        );
    }

    /** @return list<TimeSlot> Original requested times, including later cancelled meetings. */
    public function times(): array
    {
        return array_map(static fn(BookingOccurrence $occurrence): TimeSlot => $occurrence->time, $this->occurrences);
    }

    public function occurrence(EntityId $id): BookingOccurrence
    {
        foreach ($this->occurrences as $occurrence) {
            if ($occurrence->id->value === $id->value) {
                return $occurrence;
            }
        }
        throw new RecordNotFoundException('The meeting does not belong to this booking.');
    }

    public function cancelOccurrence(EntityId $id, EntityId $actor, DateTimeImmutable $at): self
    {
        if ($this->occurrence($id)->cancelledAt !== null) {
            return $this;
        }
        if ($this->status === 'cancelled') {
            throw new DomainException('This booking is already cancelled.');
        }
        $occurrences = array_map(
            static fn(BookingOccurrence $occurrence): BookingOccurrence =>
            $occurrence->id->value === $id->value ? $occurrence->cancel($actor, $at) : $occurrence,
            $this->occurrences
        );
        $active = array_filter($occurrences, static fn(BookingOccurrence $item): bool => $item->cancelledAt === null);
        return new self(
            $this->id,
            $this->roomId,
            $this->ownerUserId,
            $this->title,
            $occurrences,
            $active === [] ? 'cancelled' : $this->status,
            $this->version + 1,
            $this->categoryId,
            $this->sectionPublicationId
        );
    }

    public function transition(string $status): self
    {
        if (
            ($status === 'approved' && $this->status !== 'requested')
            || ($status === 'cancelled' && $this->status === 'cancelled')
            || !in_array($status, ['approved', 'cancelled'], true)
        ) {
            throw new DomainException('Invalid booking transition.');
        }

        return new self(
            $this->id,
            $this->roomId,
            $this->ownerUserId,
            $this->title,
            $this->occurrences,
            $status,
            $this->version + 1,
            $this->categoryId,
            $this->sectionPublicationId
        );
    }

    public function categorize(?EntityId $category): self
    {
        return new self(
            $this->id,
            $this->roomId,
            $this->ownerUserId,
            $this->title,
            $this->occurrences,
            $this->status,
            $this->version + 1,
            $category,
            $this->sectionPublicationId
        );
    }

    public function requestFingerprint(): string
    {
        $utc = new \DateTimeZone('UTC');
        $times = array_map(static fn(TimeSlot $slot): array => [
            $slot->startsAt->setTimezone($utc)->format(DATE_ATOM),
            $slot->endsAt->setTimezone($utc)->format(DATE_ATOM),
        ], $this->times());
        sort($times);
        $facts = [$this->id->value, $this->ownerUserId->value, $this->roomId?->value,
            $this->title, $times, $this->categoryId?->value, $this->sectionPublicationId?->value];
        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
    }
}
