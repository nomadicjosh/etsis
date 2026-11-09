<?php

declare(strict_types=1);

namespace Domain\Gradebook\Service;

use DateTimeImmutable;
use DomainException;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Gradebook\Assignment;
use Domain\Gradebook\AssignmentScore;
use Domain\Gradebook\Repository\GradebookRepository;
use Domain\Registration\Enrollment;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class GradebookService
{
    public function __construct(
        private GradebookRepository $repository,
        private RegistrationRepository $registration,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private \Domain\AcademicRecord\Service\InstitutionalCreditLedger $credits,
    ) {
    }

    public function addAssignment(Assignment $assignment): void
    {
        $this->access->authenticatedUserId();
        $this->faculty($assignment->sectionId);
        $this->transactions->run(function () use ($assignment): void {
            $this->registration->lockSection($assignment->sectionId);
            $actor = $this->faculty($assignment->sectionId);
            $this->repository->addAssignment($assignment);
            $this->audit->record($actor, "assignment.created", $assignment->id);
        });
    }

    /** @return array<string, mixed> */
    public function assignment(EntityId $id): array
    {
        $this->access->authenticatedUserId();
        $assignment = $this->repository->assignment($id);
        $this->faculty($assignment->sectionId);

        return [
            "id" => $id->value,
            "section_id" => $assignment->sectionId->value,
            "title" => $assignment->title,
            "possible_hundredths" => $assignment->possibleHundredths,
            "due_on" => $assignment->dueOn->format("Y-m-d"),
            "revision" => $this->assignmentRevision($assignment),
        ];
    }

    public function editAssignment(
        EntityId $id,
        string $title,
        int $possible,
        DateTimeImmutable $due,
        string $revision,
    ): void {
        $this->access->authenticatedUserId();
        $initial = $this->repository->assignment($id);
        $this->faculty($initial->sectionId);
        $this->transactions->run(function () use ($id, $initial, $title, $possible, $due, $revision): void {
            $this->registration->lockSection($initial->sectionId);
            $actor = $this->faculty($initial->sectionId);
            $current = $this->repository->assignment($id);
            if (!hash_equals($this->assignmentRevision($current), $revision)) {
                throw new ConcurrentModificationException("Reload the assignment before editing.");
            }
            if (($this->repository->highestScore($id) ?? 0) > $possible) {
                throw new ValidationException([
                    [
                        "field" => "possible_hundredths",
                        "message" => "Possible points cannot be lower than a recorded earned score.",
                    ],
                ]);
            }
            $changed = new Assignment($id, $current->sectionId, $title, $possible, $due);
            $this->repository->saveAssignment($changed);
            $this->audit->record($actor, "assignment.updated", $id);
        });
    }

    private function assignmentRevision(Assignment $assignment): string
    {
        return hash(
            "sha256",
            json_encode(
                [
                    $assignment->id->value,
                    $assignment->sectionId->value,
                    $assignment->title,
                    $assignment->possibleHundredths,
                    $assignment->dueOn->format("Y-m-d"),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function score(EntityId $assignmentId, EntityId $student, ?int $earned, int $expectedVersion): void
    {
        $this->access->authenticatedUserId();
        $initial = $this->repository->assignment($assignmentId);
        $this->faculty($initial->sectionId);
        $this->transactions->run(function () use (
            $assignmentId,
            $initial,
            $student,
            $earned,
            $expectedVersion,
        ): void {
            $this->enrolled($student, $initial->sectionId);
            $assignment = $this->repository->assignment($assignmentId);
            $actor = $this->faculty($assignment->sectionId);
            $score = new AssignmentScore($assignmentId, $student, $earned, $assignment->possibleHundredths);
            $this->repository->score($score, $expectedVersion);
            $this->audit->record($actor, "assignment.graded", $assignmentId);
        });
    }

    /** @return array<string,mixed> */
    public function gradeChoices(EntityId $student, EntityId $section, ?EntityId $assignment = null): array
    {
        $enrollment = $this->gradeEnrollment($student, $section);
        return $this->credits->choices($student, $section, $enrollment->id, $assignment);
    }
    /** @return array<string,mixed> */
    public function reviewFinalGrade(
        EntityId $student,
        EntityId $section,
        EntityId $grade,
        int $version,
        ?EntityId $assignment = null,
        #[\SensitiveParameter] string $reason = "",
        ?string $effective = null,
    ): array {
        $enrollment = $this->gradeEnrollment($student, $section);
        return $this->credits->review(
            $student,
            $section,
            $enrollment->id,
            $grade,
            $version,
            $assignment,
            $reason,
            $effective,
        );
    }
    /** @return array<string,mixed> */
    public function commitFinalGrade(EntityId $student, EntityId $section, #[\SensitiveParameter] string $token): array
    {
        return $this->credits->commit($student, $section, $token);
    }
    /** Internal command convenience; browser writes use explicit reviewed tokens. */
    public function finalGrade(
        EntityId $student,
        EntityId $section,
        EntityId $gradeId,
        int $expectedVersion,
        ?EntityId $assignment = null,
        #[\SensitiveParameter] string $reason = "",
        ?string $effective = null,
    ): void {
        $review = $this->reviewFinalGrade(
            $student,
            $section,
            $gradeId,
            $expectedVersion,
            $assignment,
            $reason,
            $effective,
        );
        $this->commitFinalGrade($student, $section, $review["review_token"]);
    }
    /** @return array<string,mixed> */
    public function gradeHistory(EntityId $student, EntityId $section, ?string $cursor = null): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->admissions->person($this->admissions->student($student)->personId);
        if ($person->ownerUserId?->value !== $actor->value) {
            try {
                $this->faculty($section);
            } catch (\Domain\Shared\Exception\AccessDeniedException) {
                $this->access->requirePermission("sis:student:read");
            }
        }
        $enrollment =
        $this->registration->enrollment($student, $section) ??
        throw new \Domain\Shared\Exception\RecordNotFoundException("Current enrollment not found.");
        return $this->credits->history($student, $section, $enrollment->id, $cursor);
    }
    private function gradeEnrollment(EntityId $student, EntityId $section): Enrollment
    {
        $this->access->authenticatedUserId();
        try {
            $this->faculty($section);
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            $this->access->requirePermission("sis:gradebook:amend");
            $this->access->requirePermission("sis:student:read");
        }
        return $this->registration->enrollment($student, $section) ??
        throw new \Domain\Shared\Exception\RecordNotFoundException("Enrollment not found.");
    }

    public function attendance(
        EntityId $student,
        EntityId $section,
        DateTimeImmutable $on,
        string $status,
        ?string $revision = null,
    ): void {
        $this->access->authenticatedUserId();
        if (!in_array($status, ["present", "absent", "late", "excused"], true)) {
            throw new DomainException("Invalid attendance status.");
        }
        $this->faculty($section);
        $this->transactions->run(function () use ($student, $section, $on, $status, $revision): void {
            $enrollment = $this->enrolled($student, $section);
            $term = $this->registration->term($enrollment->termId);
            if (
                $on->format("Y-m-d") < $term->dates->start->format("Y-m-d") ||
                $on->format("Y-m-d") > $term->dates->end?->format("Y-m-d")
            ) {
                throw new DomainException("Attendance must fall within the term.");
            }
            $actor = $this->faculty($section);
            $current = $this->repository->attendanceRecord($section, $student, $on->format("Y-m-d"));
            if ($current !== null && $revision === null && $current["status"] === $status) {
                return;
            }
            if (
                ($current !== null &&
                    ($revision === null || !hash_equals($this->attendanceRevision($current), $revision))) ||
                ($current === null && $revision !== null)
            ) {
                throw new ConcurrentModificationException(
                    "Reload attendance before changing an existing mark.",
                );
            }
            $this->repository->attendance($section, $student, $on->format("Y-m-d"), $status);
            $this->audit->record($actor, "attendance.recorded", $enrollment->id);
        });
    }

    /** @return list<array<string, mixed>> */
    public function scores(EntityId $studentId, EntityId $section): array
    {
        $actor = $this->access->authenticatedUserId();
        $student = $this->admissions->student($studentId);
        $person = $this->admissions->person($student->personId);
        if ($person->ownerUserId?->value !== $actor->value) {
            $this->faculty($section);
        }
        if ($this->registration->enrollment($studentId, $section) === null) {
            throw new DomainException("The student is not registered for this section.");
        }

        $scores = $this->repository->scores($studentId, $section);
        foreach ($scores as &$score) {
            $score["institutional_id"] = $person->institutionalId->value;
        }
        unset($score);

        return $scores;
    }

    /** @return array<string, mixed> */
    public function scorePage(EntityId $student, EntityId $section, ?EntityId $after = null): array
    {
        $this->readStudentSection($student, $section);
        $rows = $this->repository->scores($student, $section, $after, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);

        return ["items" => $rows, "next_after" => $more ? (string) $rows[49]["assignment_id"] : null];
    }

    /** @return array<string, mixed> */
    public function attendanceHistory(EntityId $student, EntityId $section, ?EntityId $after = null): array
    {
        $enrollment = $this->readStudentSection($student, $section);
        $term = $this->registration->term($enrollment->termId);
        $rows = $this->repository->attendancePage($section, $student, $after, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        foreach ($rows as &$row) {
            $row["revision"] = $this->attendanceRevision($row);
        }
        unset($row);

        return [
            "term" => [
                "starts_on" => $term->dates->start->format("Y-m-d"),
                "ends_on" => $term->dates->end?->format("Y-m-d"),
            ],
            "items" => $rows,
            "counts" => $this->repository->attendanceCounts($section, $student),
            "next_after" => $more ? (string) $rows[49]["id"] : null,
        ];
    }

    private function readStudentSection(EntityId $studentId, EntityId $section): Enrollment
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->admissions->person($this->admissions->student($studentId)->personId);
        if ($person->ownerUserId?->value !== $actor->value) {
            $this->faculty($section);
        }
        return $this->registration->enrollment($studentId, $section) ??
        throw new DomainException("The student is not registered for this section.");
    }

    /** @param array<string, mixed> $row */
    private function attendanceRevision(array $row): string
    {
        return hash(
            "sha256",
            json_encode(
                [(string) $row["id"], (string) $row["held_on"], (string) $row["status"]],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function faculty(EntityId $section): EntityId
    {
        return $this->access->requireOwnerOrPermission(
            $this->registration->section($section)->facultyUserId,
            "sis:gradebook:manage",
        );
    }

    private function enrolled(EntityId $student, EntityId $section): Enrollment
    {
        $this->registration->lockStudent($student);
        $this->registration->lockSection($section);
        $enrollment = $this->registration->enrollment($student, $section);
        if ($enrollment === null || !$enrollment->status->isEnrolled()) {
            throw new DomainException("An active enrollment is required.");
        }

        return $enrollment;
    }
}
