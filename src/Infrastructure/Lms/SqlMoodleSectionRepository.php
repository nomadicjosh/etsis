<?php

declare(strict_types=1);

namespace Infrastructure\Lms;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Lms\Repository\MoodleSectionRepository;
use Domain\Lms\Service\MoodleSectionSyncPolicy;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\Service\SectionOfferingSelection;
use Domain\Registration\Service\EnrollmentLedger;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Student\Service\StudentActivityLedger;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlMoodleSectionRepository implements MoodleSectionRepository
{
    public function __construct(
        private SqlRecords $sql,
        private RegistrationRepository $registration,
        private SectionVersionRepository $versions,
        private HistoricalEventStore $history,
        private AdmissionsRepository $people,
        private EnrollmentLedger $enrollments,
        private StudentActivityLedger $activity,
        private UnitOfWork $transactions,
        private MoodleSectionSyncPolicy $policy,
        private Clock $clock,
    ) {
    }
    public function section(EntityId $section): array
    {
        return $this->transactions->run(function () use ($section): array {
            $this->registration->lockSection($section);
            $s = $this->registration->section($section);
            $c = $this->registration->courseForSection($s);
            $p = SectionOfferingSelection::verify($section, $this->versions, $this->history);
            if ($p === null || !$p->matchesOffering($s, $c)) {
                throw new CorruptHistoryException();
            }
            return [
                "code" => $s->code,
                "title" => $c->title,
                "hash" => hash(
                    "sha256",
                    json_encode([$p->fingerprint(), $s->active, $c->active], JSON_THROW_ON_ERROR),
                ),
            ];
        });
    }
    public function directory(string $search): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT s." .
                $q("id") .
                ", s." .
                $q("code") .
                ", c." .
                $q("title") .
                ", t." .
                $q("code") .
                " AS " .
                $q("term_code") .
                " FROM " .
                $q("sis_sections") .
                " s JOIN " .
                $q("sis_courses") .
                " c ON c." .
                $q("id") .
                " = s." .
                $q("course_id") .
                " JOIN " .
                $q("sis_terms") .
                " t ON t." .
                $q("id") .
                " = s." .
                $q("term_id") .
                " WHERE LOWER(s." .
                $q("code") .
                ") LIKE ? ORDER BY s." .
                $q("id"),
            ["%" . strtolower(str_replace(["%", "_"], "", $search)) . "%"],
            20,
        );
    }
    public function link(EntityId $binding, EntityId $section): ?array
    {
        return $this->sql->rows(
            "sis_moodle_section_links",
            ["binding_id" => $binding->value, "section_id" => $section->value],
            1,
        )[0] ?? null;
    }
    public function add(
        EntityId $id,
        EntityId $binding,
        EntityId $section,
        string $course,
        EntityId $actor,
        string $at,
        #[\SensitiveParameter] string $review,
    ): void {
        try {
            $this->sql->insert("sis_moodle_section_links", [
                "id" => $id->value,
                "binding_id" => $binding->value,
                "section_id" => $section->value,
                "moodle_course_id" => $course,
                "approved_by" => $actor->value,
                "approved_at" => $at,
                "encrypted_review" => $review,
            ]);
        } catch (\PDOException) {
            throw new ConcurrentModificationException("A section or Moodle course already has a link.");
        }
    }
    public function claim(EntityId $binding, int $now): ?array
    {
        $q = $this->sql->quote(...);
        $row =
        $this->sql->fetch(
            "SELECT * FROM " .
                    $q("sis_moodle_section_links") .
                    " WHERE " .
                    $q("binding_id") .
                    " = ? AND " .
                    $q("lease_until") .
                    " <= ? AND " .
                    $q("cycle_at") .
                    " <= ? ORDER BY " .
                    $q("cycle_at") .
                    ", " .
                    $q("id"),
            [$binding->value, $now, $now - 60],
            1,
        )[0] ?? null;
        if ($row === null) {
            return null;
        }
        $v = (int) $row["version"];
        if ($v < 0 || $v >= 2147483646) {
            throw new \RuntimeException("The synchronization version needs operator review.");
        }
        if (
            $this->sql->update(
                "sis_moodle_section_links",
                ["version" => $v + 1, "lease_until" => $now + 60],
                ["id" => $row["id"], "version" => $v],
            ) !== 1
        ) {
            return null;
        }
        $row["version"] = $v + 1;
        return $row;
    }
    public function candidate(EntityId $section, EntityId $link, ?EntityId $after): ?EntityId
    {
        $q = $this->sql->quote(...);
        $p = $this->versions->selected($section);
        $faculty = $p?->definition->data["faculty_person_id"];
        $params = [$section->value, $link->value];
        $query =
        "SELECT st." .
        $q("person_id") .
        " FROM " .
        $q("sis_enrollments") .
        " e JOIN " .
        $q("sis_students") .
        " st ON st." .
        $q("id") .
        " = e." .
        $q("student_id") .
        " WHERE e." .
        $q("section_id") .
        " = ? UNION SELECT " .
        $q("person_id") .
        " FROM " .
        $q("sis_moodle_section_members") .
        " WHERE " .
        $q("link_id") .
        " = ?";
        if (is_string($faculty)) {
            $query .=
            " UNION SELECT " .
            $q("id") .
            " AS " .
            $q("person_id") .
            " FROM " .
            $q("sis_persons") .
            " WHERE " .
            $q("id") .
            " = ?";
            $params[] = $faculty;
        }
        $query = "SELECT " . $q("person_id") . " FROM (" . $query . ") candidates";
        if ($after !== null) {
            $query .= " WHERE " . $q("person_id") . " > ?";
            $params[] = $after->value;
        }
        $row = $this->sql->fetch($query . " ORDER BY " . $q("person_id"), $params, 1)[0] ?? null;
        return $row === null ? null : new EntityId($row["person_id"]);
    }
    public function desired(EntityId $section, EntityId $link, EntityId $person): array
    {
        return $this->transactions->run(function () use ($section, $link, $person): array {
            $studentId = $this->people->studentForPerson($person);
            if ($studentId !== null) {
                $this->registration->lockStudent($studentId);
            }
            $context = $this->section($section);
            $selected = $this->versions->selected($section) ?? throw new CorruptHistoryException();
            $d = $selected->definition->data;
            $s = $this->registration->section($section);
            $owner = $this->people->person($person)->ownerUserId;
            $today = $this->clock->now();
            $start = new \DateTimeImmutable($d["instruction_starts_on"], $today->getTimezone())->modify(
                "-" . $this->policy->beforeDays() . " days",
            );
            $end = new \DateTimeImmutable($d["instruction_ends_on"], $today->getTimezone())->modify(
                "+" . $this->policy->afterDays() . " days",
            );
            $within =
                $s->active &&
                $today->format("Y-m-d") >= $start->format("Y-m-d") &&
                $today->format("Y-m-d") <= $end->format("Y-m-d");
            $teacher = $d["faculty_person_id"] === $person->value && $owner?->value === $d["faculty_user_id"];
            $facultyRole = $owner !== null && $this->role($owner, "faculty");
            $issue = $teacher && !$facultyRole ? "faculty_role_required" : "none";
            $notEnded = $today->format("Y-m-d") <= $end->format("Y-m-d");
            $instructor = $s->active && $notEnded && $teacher && $facultyRole;
            $isStudent = false;
            $eligibleStudent = false;
            $enrollmentId = null;
            $activityVersion = null;
            $facts = null;
            $old =
                $this->sql->rows(
                    "sis_moodle_section_members",
                    ["link_id" => $link->value, "person_id" => $person->value],
                    1,
                )[0] ?? null;
            if ($studentId !== null) {
                $student = $this->people->student($studentId);
                $activityVersion = $this->activity->verify($student);
                $enrollment = $this->registration->enrollment($studentId, $section);
                $enrollmentId = $enrollment?->id->value ?? ($old["enrollment_id"] ?? null);
                if ($enrollmentId !== null) {
                    $verified = $this->enrollments->evaluationFacts(
                        new EntityId($enrollmentId),
                        $studentId,
                        new HistoryReadBudget(100, 2000),
                    );
                    $facts = $verified["facts"];
                    if ($facts["section_id"] !== $section->value || !$verified["history_recorded"]) {
                        throw new CorruptHistoryException();
                    }
                    $active = in_array($facts["status"], ["A", "N"], true);
                    if (
                        ($enrollment === null && $active) ||
                        ($enrollment !== null && $enrollment->status->value !== $facts["status"])
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $eligibleStudent =
                        $s->active &&
                        $notEnded &&
                        $active &&
                        $student->active &&
                        $owner !== null &&
                        $this->role($owner, "student");
                    $isStudent = $within && $eligibleStudent;
                    if ($active && $student->active && !$isStudent && $within) {
                        $issue = "student_setup_required";
                    }
                }
            }
            return [
                "account_eligible" => $eligibleStudent || $instructor,
                "student" => $isStudent,
                "instructor" => $instructor,
                "enrollment_id" => $enrollmentId,
                "hash" => hash(
                    "sha256",
                    json_encode(
                        [
                            $context,
                            $person->value,
                            $owner?->value,
                            $within,
                            $isStudent,
                            $eligibleStudent,
                            $instructor,
                            $facts,
                            $activityVersion,
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
                "issue" => $issue,
            ];
        });
    }
    private function role(EntityId $owner, string $name): bool
    {
        $role = $this->sql->rows("sis_access_roles", ["name" => $name], 1)[0] ?? null;
        return $role !== null &&
        $this->sql->rows(
            "sis_access_user_roles",
            ["user_id" => $owner->value, "role_id" => $role["id"]],
            1,
        ) !== [];
    }
    public function remember(EntityId $link, EntityId $person, ?string $enrollment): void
    {
        $old =
        $this->sql->rows(
            "sis_moodle_section_members",
            ["link_id" => $link->value, "person_id" => $person->value],
            1,
        )[0] ?? null;
        if ($old === null) {
            $this->sql->insert("sis_moodle_section_members", [
                "id" => EntityId::generate()->value,
                "link_id" => $link->value,
                "person_id" => $person->value,
                "enrollment_id" => $enrollment,
            ]);
        } elseif ($enrollment !== null && $old["enrollment_id"] !== $enrollment) {
            $this->sql->update(
                "sis_moodle_section_members",
                ["enrollment_id" => $enrollment],
                ["id" => $old["id"]],
            );
        }
    }
    public function issues(EntityId $link): int
    {
        $q = $this->sql->quote(...);
        $row = $this->sql->fetch(
            "SELECT COUNT(*) AS " .
                $q("pending") .
                " FROM " .
                $q("sis_moodle_section_members") .
                " WHERE " .
                $q("link_id") .
                " = ? AND " .
                $q("issue_code") .
                " <> ?",
            [$link->value, "none"],
            1,
        )[0];
        return (int) $row["pending"];
    }
    public function memberResult(EntityId $link, EntityId $person, string $hash, string $issue): void
    {
        $this->sql->update(
            "sis_moodle_section_members",
            ["source_hash" => $hash, "issue_code" => $issue],
            ["link_id" => $link->value, "person_id" => $person->value],
        );
    }
    public function finish(
        EntityId $link,
        int $version,
        ?EntityId $after,
        int $cycleAt,
        int $leaseUntil,
        string $issue,
    ): void {
        if (
            $this->sql->update(
                "sis_moodle_section_links",
                [
                    "after_person" => $after?->value,
                    "cycle_at" => $cycleAt,
                    "lease_until" => $leaseUntil,
                    "issue_code" => $issue,
                ],
                ["id" => $link->value, "version" => $version],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The section synchronization claim changed.");
        }
    }
}
