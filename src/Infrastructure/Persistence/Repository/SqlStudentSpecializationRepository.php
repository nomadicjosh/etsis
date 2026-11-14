<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentSpecializationRepository;
use Domain\Student\ValueObject\StudentSpecializationSchema;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlStudentSpecializationRepository implements StudentSpecializationRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function get(EntityId $assignment): ?array
    {
        $row = $this->sql->rows('sis_student_specializations', ['id' => $assignment->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        try {
            $position = filter_var($row['stream_version'], FILTER_VALIDATE_INT);
            $revision = filter_var($row['cohort_binding_revision'], FILTER_VALIDATE_INT);
            if ($position === false || $position < 1 || $revision === false) {
                throw new CorruptHistoryException();
            }
            $payload = $row;
            unset($payload['id'], $payload['stream_version'], $payload['selected_json']);
            $payload['assignment_id'] = $assignment->value;
            $payload['cohort_binding_revision'] = $revision;
            $payload['specialization_ids'] = json_decode($row['selected_json'], true, 4, JSON_THROW_ON_ERROR);
            return ['payload' => StudentSpecializationSchema::read($payload), 'position' => $position];
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }
    public function project(EntityId $assignment, array $payload, int $position): void
    {
        $payload = StudentSpecializationSchema::read($payload);
        if ($payload['assignment_id'] !== $assignment->value || $position < 1) {
            throw new CorruptHistoryException();
        }
        $row = $payload;
        unset($row['assignment_id'], $row['specialization_ids']);
        $row['stream_version'] = $position;
        $row['selected_json'] = json_encode($payload['specialization_ids'], JSON_THROW_ON_ERROR);
        if ($this->sql->rows('sis_student_specializations', ['id' => $assignment->value], 1) === []) {
            $this->sql->insert('sis_student_specializations', ['id' => $assignment->value] + $row);
        } else {
            $this->sql->update('sis_student_specializations', $row, ['id' => $assignment->value]);
        }
    }
}
