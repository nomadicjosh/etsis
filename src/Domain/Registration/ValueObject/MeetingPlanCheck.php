<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Minimized operator observation, not a publication, repair or independent archive checkpoint. */
final readonly class MeetingPlanCheck
{
    public function __construct(
        public EntityId $section,
        public string $status,
        public int $events,
        public ?int $publications = null,
        public ?int $meetings = null,
        public array $issues = [],
    ) {
        if (
            !in_array($status, ['verified', 'observed_empty', 'unverified', 'changed', 'limited'], true)
            || $events < 0 || $events > 2000
            || ($status === 'verified' ? $publications === null || $publications < 1 || $publications > $events
                || $meetings === null || $meetings < 0 || $meetings > 500
                : $publications !== null || $meetings !== null)
            || !array_is_list($issues)
            || array_diff($issues, ['identity', 'journal', 'projection', 'offering_reference', 'unavailable']) !== []
            || ($status === 'unverified' ? $issues === [] : $issues !== [])
        ) {
            throw new InvalidArgumentException('Use a bounded, consistent meeting-plan observation.');
        }
    }

    public function describe(): array
    {
        return ['scope' => 'section_meeting_plan', 'section_reference' => $this->section->value,
            'status' => $this->status, 'events_checked' => $this->events, 'publications_checked' => $this->publications,
            'current_meetings_checked' => $this->meetings, 'issues' => $this->issues, 'records_changed' => false];
    }
}
