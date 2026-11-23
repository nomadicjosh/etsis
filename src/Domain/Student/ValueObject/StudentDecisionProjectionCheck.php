<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Value-free observation. Counts describe only successfully compared retained records. */
final readonly class StudentDecisionProjectionCheck
{
    public function __construct(
        public string $status,
        public string $scope,
        public EntityId $reference,
        public int $events,
        public ?int $position = null,
        public ?int $records = null,
        public array $issues = [],
    ) {
        $complete = in_array($status, ['verified', 'observed_empty'], true);
        if (
            !in_array($scope, ['student_standing', 'enrollment_load_determination'], true)
            || !in_array($status, ['verified', 'observed_empty', 'unverified', 'changed', 'limited'], true)
            || $events < 0 || $events > 2000
            || ($position !== null && $position < 0)
            || ($complete !== ($records !== null))
            || ($complete && $position === null)
            || ($records !== null && ($records < 0 || $records > 50))
            || !array_is_list($issues)
            || array_diff($issues, ['pursuit_identity', 'standing', 'load_decision', 'unavailable']) !== []
            || ($status === 'unverified' ? $issues === [] : $issues !== [])
            || ($status === 'verified' && $position === 0)
            || ($status === 'observed_empty' && ($scope !== 'student_standing' || $position !== 0 || $records !== 0))
            || ($complete && $scope === 'enrollment_load_determination' && ($position !== 1 || $records !== 1))
        ) {
            throw new InvalidArgumentException('Use a bounded, consistent student-decision verification result.');
        }
    }

    public function describe(): array
    {
        return [
            'status' => $this->status,
            'scope' => $this->scope,
            $this->scope === 'student_standing' ? 'pursuit_reference' : 'determination_reference' =>
                $this->reference->value,
            'events_checked' => $this->events,
            'position' => $this->position,
            'records_checked' => $this->records,
            'issues' => $this->issues,
            'records_changed' => false,
        ];
    }
}
