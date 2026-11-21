<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Minimal maintenance result, never academic facts or restricted removal references. */
final readonly class AcademicProjectionCheck
{
    /** @param list<string> $issues */
    public function __construct(
        public string $status,
        public EntityId $student,
        public int $events,
        public ?int $records,
        public array $issues = [],
    ) {
        $complete = in_array($status, ['verified', 'history_gaps', 'observed_empty'], true);
        if (
            !in_array(
                $status,
                ['verified', 'history_gaps', 'observed_empty', 'unverified', 'changed', 'limited'],
                true,
            ) ||
            $events < 0 || $events > 2000 || !array_is_list($issues) ||
            array_diff($issues, ['student_identity', 'academic_sources', 'unavailable']) !== [] ||
            ($complete && ($records === null || $records < 0 || $records > 200 || $issues !== [])) ||
            (!$complete && $records !== null) || ($status === 'observed_empty' && $records !== 0) ||
            ($status === 'unverified' && $issues === []) || ($status !== 'unverified' && $issues !== [])
        ) {
            throw new InvalidArgumentException('Use a bounded, consistent academic verification result.');
        }
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        return [
            'status' => $this->status,
            'scope' => 'student_academic_inputs',
            'student_reference' => $this->student->value,
            'events_checked' => $this->events,
            'current_records' => $this->records,
            'issues' => $this->issues,
            'records_changed' => false,
        ];
    }
}
