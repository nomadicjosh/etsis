<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\AcademicRecord\Service\InstitutionalCreditLedger;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\DegreeAudit\Repository\AcademicWorkRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Transfer\Service\TransferCreditLedger;

/** Authorized current academic inputs. This neither allocates courses nor calculates completion. */
final readonly class AcademicWorkService
{
    public function __construct(
        private AcademicWorkRepository $work,
        private AdmissionsRepository $admissions,
        private StudentRecordRepository $students,
        private InstitutionalCreditLedger $institutional,
        private TransferCreditLedger $transfers,
        private StudentAccess $access,
        private UnitOfWork $transactions,
    ) {
    }

    /** @return array<string,mixed> */
    public function page(EntityId $student, EntityId $assignment, ?EntityId $after = null): array
    {
        $this->authorize($student, $assignment);
        return $this->transactions->run(function () use ($student, $assignment, $after): array {
            $this->students->lock($student);
            $this->authorize($student, $assignment);
            $rows = $this->work->page($student, $after);
            $items = [];
            foreach (array_slice($rows, 0, 20) as $row) {
                $id = new EntityId($row["id"]);
                $input = match ($row["credit_type"]) {
                    "institutional" => $this->institutional->auditFacts($id, $student),
                    "transfer" => $this->transfers->auditFacts($id, $student),
                    default => throw new CorruptHistoryException(),
                };
                $facts = $input["credit"];
                if ($facts["id"] !== $id->value || $facts["source_id"] !== $row["source_id"]) {
                    throw new CorruptHistoryException();
                }
                $items[] = [
                    "id" => $id->value,
                    "course_id" => $facts["course_id"],
                    "course_code" => $facts["course_code"],
                    "course_title" => $facts["course_title"],
                    "credit_type" => $facts["credit_type"],
                    "term_id" => $facts["term_id"],
                    "term_code" => $input["retained_term_code"] ?? $row["term_code"],
                    "term_label_retained" => $input["retained_term_code"] !== null,
                    "completed_on" => $input["completed_on"],
                    "attempted_milli" => $facts["attempted_milli"],
                    "completed_milli" => $facts["completed_milli"],
                    "quality_points_milli" => $facts["grade_points_milli"],
                    "grade" => $input["grade"],
                    "grade_recorded" => $facts["grade_id"] !== null,
                    "status" => $facts["status"],
                    "course_version_id" => $facts["course_version_id"] ?? null,
                    "section_version_id" => $input["section_version_id"],
                    "history_recorded" => $input["history_recorded"],
                    "earlier_history_unknown" => $input["earlier_history_unknown"],
                    "history_version" => $input["history_version"],
                ];
            }
            $this->authorize($student, $assignment);
            return [
                "student_id" => $student->value,
                "assignment_id" => $assignment->value,
                "items" => $items,
                "next_after" => count($rows) > 20 ? $rows[19]["id"] : null,
            ];
        });
    }

    private function authorize(EntityId $student, EntityId $assignment): void
    {
        $this->access->authenticatedUserId();
        $record = $this->admissions->student($student);
        $this->access->requireOwnerOrPermission(
            $this->admissions->person($record->personId)->ownerUserId,
            "sis:student:read",
        );
        if ($this->students->assignment($assignment)->studentId->value !== $student->value) {
            throw new RecordNotFoundException("Student program not found.");
        }
    }
}
