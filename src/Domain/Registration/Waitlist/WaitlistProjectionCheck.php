<?php

declare(strict_types=1);

namespace Domain\Registration\Waitlist;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Minimized result. No request outcome, person, rank, private reason or business payload. */
final readonly class WaitlistProjectionCheck
{
    public function __construct(
        public string $status,
        public EntityId $entry,
        public int $events,
        public ?int $position = null,
        public array $issues = [],
    ) {
        if (
            !in_array($status, ['verified', 'unverified', 'changed', 'limited'], true)
            || $events < 0 || $events > 2000
            || ($position !== null && ($position < 1 || $position > SectionWaitlist::MAX_EVENTS))
            || ($status === 'verified' && ($position === null || $events !== $position))
            || !array_is_list($issues)
            || array_diff($issues, ['waitlist_entry', 'student_identity', 'unavailable']) !== []
            || ($status === 'unverified' ? $issues === [] : $issues !== [])
        ) {
            throw new InvalidArgumentException('Use a bounded, consistent waitlist observation.');
        }
    }

    public function describe(): array
    {
        return [
            'status' => $this->status,
            'scope' => 'waitlist_entry',
            'waitlist_reference' => $this->entry->value,
            'events_checked' => $this->events,
            'position' => $this->position,
            'records_checked' => $this->status === 'verified' ? 1 : null,
            'issues' => $this->issues,
            'records_changed' => false,
        ];
    }
}
