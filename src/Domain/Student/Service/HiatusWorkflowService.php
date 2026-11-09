<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Enum\HiatusCode;
use Domain\Student\Repository\HiatusImpactRepository;
use Domain\Student\Repository\HiatusRepository;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\StudentHiatus;
use Domain\Student\ValueObject\HiatusImpact;
use Domain\Student\ValueObject\HiatusPolicy;

final readonly class HiatusWorkflowService
{
    public function __construct(
        private HiatusService $hiatuses,
        private HiatusRepository $history,
        private HiatusImpactRepository $impacts,
        private RegistrationRepository $registration,
        private StudentRecordRepository $students,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private HiatusPolicy $policy,
        private \Domain\Registration\Service\CourseDropService $drops,
        private \Domain\Registration\Service\DropPolicyService $dropPolicies,
    ) {
    }

    /** @return array<string, mixed> */
    public function review(EntityId $student, HiatusCode $code, DateRange $dates): array
    {
        $this->access->requirePermission("sis:registration:manage");
        $routing = $this->routing($student, $dates);
        return $this->transactions->run(function () use ($student, $code, $dates, $routing): array {
            $this->lock($student, $routing);
            $context = $this->context($student, $code, $dates, $routing);
            $proposal = $this->policy->for($code);
            $now = $context["effective_now"];
            return $context + [
                "revision" => $this->revision($context),
                "operation_id" => EntityId::generate()->value,
                "defaults" => [
                    "deactivate" => $proposal["deactivate"] && $now && $context["student"]["active"],
                    "section_ids" =>
                        $proposal["courses"] && $now
                            ? array_column(
                                array_filter($context["courses"], static fn(array $row): bool => $row["eligible"]),
                                "section_id",
                            )
                            : [],
                ],
            ];
        });
    }

    /** @return array<string, mixed> */
    public function commit(StudentHiatus $hiatus, #[\SensitiveParameter] string $note, HiatusImpact $impact): array
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        if ($this->history->find($hiatus->id) !== null) {
            return $this->replay($hiatus, $note, $impact);
        }
        $routing = $this->routing($hiatus->studentId, $hiatus->dates);
        return $this->transactions->run(function () use ($hiatus, $note, $impact, $routing, $actor): array {
            $this->lock($hiatus->studentId, $routing);
            if ($this->history->find($hiatus->id) !== null) {
                return $this->replay($hiatus, $note, $impact);
            }
            $context = $this->context($hiatus->studentId, $hiatus->code, $hiatus->dates, $routing);
            if (!hash_equals($this->revision($context), $impact->revision)) {
                throw new ConcurrentModificationException("Review the current hiatus plan before confirming.");
            }
            if (!$context["effective_now"] && ($impact->deactivate || $impact->sections !== [])) {
                throw new ValidationException([
                    [
                        "field" => "starts_on",
                        "message" => "Course and activity changes need an effective date today or earlier.",
                    ],
                ]);
            }
            $courses = array_column($context["courses"], null, "section_id");
            $changes = [];
            foreach ($impact->sections as $section) {
                $row = $courses[$section->value] ?? null;
                if ($row === null || !$row["eligible"]) {
                    throw new ValidationException([
                        [
                            "field" => "sections",
                            "message" =>
                                "Only reviewed, ungraded enrollments valid on the effective date can be changed.",
                        ],
                    ]);
                }
                $changes[] = [
                    "enrollment_id" => $row["id"],
                    "section_id" => $section->value,
                    "course_code" => $row["course_code"],
                    "term_code" => $row["term_code"],
                    "previous_status" => $row["status"],
                    "status" => $row["proposed_status"],
                    "effective_date" => $context["starts_on"],
                    "grade_code" => $row["drop_policy"]["grade_code"],
                    "academic_records_removed" => $row["drop_policy"]["delete_records"],
                    "course_charge" => $row["drop_policy"]["delete_records"] ? "reverse_if_present" : "kept",
                ];
            }
            $result = [
                "course_changes" => $changes,
                "student_activity_changed" => $impact->deactivate && $context["student"]["active"],
                "student_active" => $impact->deactivate ? false : $context["student"]["active"],
                "automatic_refund" => false,
            ];
            foreach ($impact->sections as $index => $section) {
                $row = $courses[$section->value];
                $enrollment = $this->registration->enrollment($hiatus->studentId, $section);
                if ($enrollment === null || $enrollment->version !== $row["version"]) {
                    throw new ConcurrentModificationException("The enrollment changed before confirmation.");
                }
                $dropped = $this->drops->dropEffective($hiatus->studentId, $section, $context["starts_on"]);
                $result["course_changes"][$index]["course_charge"] = $dropped["course_charge"];
                $this->audit->record($actor, "registration.effective_dropped", $enrollment->id);
            }
            $recorded = new HiatusImpact($impact->deactivate, $impact->revision, $result, ...$impact->sections);
            $this->hiatuses->create($hiatus, $note, $recorded);
            if ($result["student_activity_changed"]) {
                $this->students->setActive($hiatus->studentId, false);
                $this->audit->record($actor, "student.activity_updated", $hiatus->studentId);
            }
            return ["id" => $hiatus->id->value, "impact" => $result];
        });
    }

    /** @return array<string, mixed> */
    private function replay(StudentHiatus $hiatus, #[\SensitiveParameter] string $note, HiatusImpact $impact): array
    {
        $this->hiatuses->create($hiatus, $note, $impact);
        return [
            "id" => $hiatus->id->value,
            "impact" => $this->hiatuses->view($hiatus->studentId, $hiatus->id)["impact"],
        ];
    }

    /** @return array{person: EntityId, rows: list<array<string, mixed>>} */
    private function routing(EntityId $student, DateRange $dates): array
    {
        $rows = $this->impacts->enrollments($student, $dates->start->format("Y-m-d"));
        $this->bounded($rows);
        return ["person" => $this->admissions->student($student)->personId, "rows" => $rows];
    }

    /** @param array{person: EntityId, rows: list<array<string, mixed>>} $routing */
    private function lock(EntityId $student, array $routing): void
    {
        $this->registration->lockStudent($student);
        $this->registration->lockPerson($routing["person"]);
        foreach (["section_id", "course_id", "term_id"] as $field) {
            $ids = array_unique(array_column($routing["rows"], $field));
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $entity = new EntityId($id);
                if ($field === "section_id") {
                    $this->registration->lockSection($entity);
                } elseif ($field === "course_id") {
                    $this->registration->lockCourse($entity);
                } else {
                    $this->impacts->lockTerm($entity);
                }
            }
        }
    }

    /** @param array{person: EntityId, rows: list<array<string, mixed>>} $routing
     * @return array<string, mixed>
     */
    private function context(EntityId $studentId, HiatusCode $code, DateRange $dates, array $routing): array
    {
        $student = $this->admissions->student($studentId);
        $person = $this->admissions->person($student->personId);
        $rows = $this->impacts->enrollments($studentId, $dates->start->format("Y-m-d"));
        $this->bounded($rows);
        if (array_column($rows, "id") !== array_column($routing["rows"], "id")) {
            throw new ConcurrentModificationException("The enrollment selection changed. Review again.");
        }
        $effective = $dates->start->format("Y-m-d");
        $now = $this->clock->now();
        foreach ($rows as &$row) {
            $registered = new DateTimeImmutable($row["registered_at"], new DateTimeZone("UTC"))
                ->setTimezone($now->getTimezone())
                ->format("Y-m-d");
            $posted = $row["grade_id"] !== null || $row["completed_milli"] !== 0 || $row["grade_points_milli"] !== 0;
            $row["eligible"] = !$posted && $registered <= $effective && $effective <= $now->format("Y-m-d");
            $row["reason"] = $posted
            ? "Final grade posted — keep; use the grade-change review process."
            : ($registered > $effective
                    ? "Registered after this effective date — review separately."
                    : ($effective > $now->format("Y-m-d")
                        ? "Scheduled date — record history without immediate changes."
                        : ""));
            unset($row["grade_id"], $row["completed_milli"], $row["grade_points_milli"]);
            $row["drop_policy"] = $this->dropPolicies->outcome(new EntityId($row["term_id"]), $effective);
            $row["proposed_status"] = $row["drop_policy"]["delete_records"] ? "D" : "W";
        }
        unset($row);
        return [
            "student" => [
                "id" => $studentId->value,
                "given_name" => $person->givenName,
                "family_name" => $person->familyName,
                "institutional_id" => $person->institutionalId->value,
                "active" => $student->active,
            ],
            "code" => $code->value,
            "label" => $code->label(),
            "starts_on" => $effective,
            "ends_on" => $dates->end?->format("Y-m-d"),
            "effective_now" => $effective <= $now->format("Y-m-d"),
            "courses" => $rows,
            "today" => $now->format("Y-m-d"),
            "timezone" => $now->getTimezone()->getName(),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function bounded(array $rows): void
    {
        if (count($rows) > 100) {
            throw new ValidationException([
                [
                    "field" => "sections",
                    "message" => "This case exceeds 100 open enrollments and requires a focused registrar review.",
                ],
            ]);
        }
    }

    /** @param array<string, mixed> $context */
    private function revision(array $context): string
    {
        return hash("sha256", json_encode($context, JSON_THROW_ON_ERROR));
    }
}
