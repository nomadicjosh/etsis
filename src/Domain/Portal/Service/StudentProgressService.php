<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\DegreeAudit\Service\DegreeProgressReader;
use Domain\Portal\Repository\CampusRepository;
use Domain\Portal\ValueObject\StudentDegreeProgress;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;

/** Strict portal ownership; staff permissions cannot select another person's records here. */
final readonly class StudentProgressService
{
    public function __construct(
        private CampusRepository $campus,
        private StudentRecordRepository $students,
        private DegreeProgressReader $progress,
        private StudentAccess $access,
    ) {
    }
    /**
     * Indexed pursuit choices, without degree or academic-history replay.
     * @return array<string,mixed>
     */
    public function programs(EntityId $student, ?EntityId $after = null): array
    {
        $actor = $this->owner($student);
        $rows = $this->students->assignments($student, $after, 21);
        $items = array_map(
            static fn(array $row): array => array_intersect_key(
                $row,
                array_flip([
                    "id",
                    "program_code",
                    "program_title",
                    "term_code",
                    "status",
                    "catalog_year",
                    "catalog_revision",
                ]),
            ),
            array_slice($rows, 0, 20),
        );
        $this->owner($student, $actor);
        return ["items" => $items, "next_after" => count($rows) > 20 ? $rows[19]["id"] : null];
    }
    /**
     * One owner-selected pursuit, using the existing bounded canonical progress calculation.
     * @return array<string,mixed>
     */
    public function review(EntityId $student, EntityId $assignment): array
    {
        $actor = $this->scope($student, $assignment);
        $result = new StudentDegreeProgress($this->progress->student($student, $assignment));
        $this->scope($student, $assignment, $actor);
        return $result->describe();
    }
    private function owner(EntityId $student, ?EntityId $expected = null): EntityId
    {
        $actor = $this->access->authenticatedUserId();
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException("Sign-in changed. Reload your student services.");
        }
        $this->campus->ownedStudent($actor, $student);
        return $actor;
    }
    private function scope(EntityId $student, EntityId $assignment, ?EntityId $expected = null): EntityId
    {
        $actor = $this->owner($student, $expected);
        if ($this->students->assignment($assignment)->studentId->value !== $student->value) {
            throw new RecordNotFoundException("Student program not found.");
        }
        return $actor;
    }
}
