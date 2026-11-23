<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Fixed, value-free diagnostic; no application answers, outcomes or decision reasons. */
final readonly class ApplicationProjectionCheck
{
    /** @param list<string> $issues */
    public function __construct(
        public string $status,
        public EntityId $application,
        public int $events,
        public ?int $decisionPosition = null,
        public ?int $matriculationPosition = null,
        public array $issues = [],
        public ?bool $earlierHistoryUnknown = null,
    ) {
        $complete = in_array($status, ['verified', 'history_gaps'], true);
        if (
            !in_array($status, ['verified', 'history_gaps', 'unverified', 'changed', 'limited'], true)
            || $events < 0 || $events > 2000
            || ($decisionPosition !== null && $decisionPosition < 0)
            || ($matriculationPosition !== null && $matriculationPosition < 0)
            || !array_is_list($issues)
            || array_diff($issues, ['journal', 'application', 'intake', 'unavailable']) !== []
            || ($status === 'unverified' ? $issues === [] : $issues !== [])
            || ($complete && ($decisionPosition === null || $matriculationPosition === null))
            || ($complete !== ($earlierHistoryUnknown !== null))
            || ($status === 'verified' && ($decisionPosition === 0 || $earlierHistoryUnknown))
            || ($status === 'history_gaps' && !$earlierHistoryUnknown)
        ) {
            throw new InvalidArgumentException('Use a bounded, consistent application-verification result.');
        }
    }

    public function describe(): array
    {
        return ['status' => $this->status, 'scope' => 'admissions_application',
            'application_reference' => $this->application->value, 'events_checked' => $this->events,
            'decision_position' => $this->decisionPosition, 'matriculation_position' => $this->matriculationPosition,
            'issues' => $this->issues, 'earlier_history_unknown' => $this->earlierHistoryUnknown,
            'records_changed' => false];
    }
}
