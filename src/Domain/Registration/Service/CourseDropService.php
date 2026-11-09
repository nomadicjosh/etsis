<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Registration\Enrollment;
use Domain\Registration\Enum\RegistrationStatus;
use Domain\Registration\Repository\CourseDropRepository;
use Domain\Registration\Repository\DropPolicyRepository;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class CourseDropService
{
    public function __construct(
        private CourseDropRepository $drops,
        private DropPolicyRepository $policies,
        private DropPolicyService $policy,
        private RegistrationRepository $registration,
        private AdmissionsRepository $admissions,
        private RegistrationBilling $billing,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private MessageCipher $cipher,
    ) {
    }

    private function authorize(EntityId $student): EntityId
    {
        $actor = $this->access->authenticatedUserId();
        $record = $this->admissions->student($student);
        $this->access->requireOwnerOrPermission(
            $this->admissions->person($record->personId)->ownerUserId,
            "sis:registration:manage",
        );
        return $actor;
    }
    /** @return array<string, mixed> */
    public function review(EntityId $student, EntityId $section, ?string $effective = null): array
    {
        $this->authorize($student);
        if ($effective !== null) {
            $this->access->requirePermission("sis:registration:manage");
        }
        $date = $effective ?? $this->clock->now()->format("Y-m-d");
        $routing = $this->routing($student, $section);
        return $this->transactions->run(function () use ($student, $section, $date, $routing): array {
            $this->lock($student, $section, $routing);
            $context = $this->context($student, $section, $date);
            return $context + ["operation_id" => EntityId::generate()->value, "revision" => $this->revision($context)];
        });
    }
    /** @return array<string, mixed> */
    public function commit(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        int $version,
        EntityId $operation,
        string $revision,
        string $effective,
        bool $effectiveDated = false,
    ): array {
        $actor = $this->authorize($student);
        if ($effectiveDated) {
            $this->access->requirePermission("sis:registration:manage");
        }
        $request = [
            "student_id" => $student->value,
            "section_id" => $section->value,
            "enrollment_id" => $enrollment->value,
            "version" => $version,
            "revision" => $revision,
            "effective_on" => $effective,
            "effective_dated" => $effectiveDated,
        ];
        $hash = hash("sha256", json_encode($request, JSON_THROW_ON_ERROR));
        if (($old = $this->drops->receipt($operation)) !== null) {
            return $this->replay($old, $actor, $hash);
        }
        $routing = $this->routing($student, $section);
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $version,
            $operation,
            $revision,
            $effective,
            $effectiveDated,
            $actor,
            $hash,
            $routing,
        ): array {
            $this->lock($student, $section, $routing);
            if (($old = $this->drops->receipt($operation)) !== null) {
                return $this->replay($old, $actor, $hash);
            }
            if (!$effectiveDated && $effective !== $this->clock->now()->format("Y-m-d")) {
                throw new ConcurrentModificationException("The institutional date changed. Review this drop again.");
            }
            $context = $this->context($student, $section, $effective);
            if (
                $context["enrollment_id"] !== $enrollment->value ||
                $context["version"] !== $version ||
                !hash_equals($this->revision($context), $revision)
            ) {
                throw new ConcurrentModificationException("This enrollment or drop policy changed. Review it again.");
            }
            $current =
                $this->registration->enrollment($student, $section) ??
                throw new RecordNotFoundException("Enrollment not found.");
            $when = new DateTimeImmutable($effective, $this->clock->now()->getTimezone());
            $closed = $current->withdrawOnDate($this->registration->term($current->termId), $when);
            $this->drops->apply($closed, $version, $context["credit"], $context["outcome"]);
            $reversed =
                $context["outcome"]["delete_records"] &&
                $this->billing->reverseCourseCharge($student, $current->termId, $current->id, $actor);
            $result = [
                "operation_id" => $operation->value,
                "student_id" => $student->value,
                "section_id" => $section->value,
                "enrollment_id" => $closed->id->value,
                "status" => $closed->status->value,
                "version" => $closed->version,
                "effective_on" => $effective,
                "grade_code" => $context["outcome"]["grade_code"],
                "academic_records_removed" => $context["outcome"]["delete_records"],
                "course_charge" => $context["outcome"]["delete_records"] ? ($reversed ? "reversed" : "none") : "kept",
                "cash_refund_issued" => false,
                "term_id" => $closed->termId->value,
                "registered_at" => $closed->registeredAt->format(DATE_ATOM),
                "status_at" => $closed->statusAt->format(DATE_ATOM),
            ];
            $this->drops->record(
                $operation,
                $student,
                $section,
                $actor,
                $enrollment,
                $closed->version,
                $hash,
                $this->cipher->seal(json_encode(["before" => $context, "result" => $result], JSON_THROW_ON_ERROR)),
                $this->clock
                    ->now()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format(DATE_ATOM),
            );
            $this->audit->record(
                $actor,
                $context["outcome"]["delete_records"] ? "registration.removed" : "registration.withdrawn",
                $enrollment,
            );
            return $result;
        });
    }
    /** @return array<string, mixed> */
    public function receipt(EntityId $operation): array
    {
        $actor = $this->access->authenticatedUserId();
        $row = $this->drops->receipt($operation) ?? throw new RecordNotFoundException("Drop receipt not found.");
        $this->authorize(new EntityId($row["student_id"]));
        if ($row["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("Access denied.");
        }
        return json_decode($this->cipher->open($row["encrypted_content"]), true, 32, JSON_THROW_ON_ERROR)["result"];
    }
    public function drop(EntityId $student, EntityId $section): Enrollment
    {
        $r = $this->review($student, $section);
        return $this->asEnrollment(
            $this->commit(
                $student,
                $section,
                new EntityId($r["enrollment_id"]),
                $r["version"],
                new EntityId($r["operation_id"]),
                $r["revision"],
                $r["effective_on"],
            ),
        );
    }
    /** @return array<string, mixed> */
    public function dropEffective(EntityId $student, EntityId $section, string $effective): array
    {
        $r = $this->review($student, $section, $effective);
        return $this->commit(
            $student,
            $section,
            new EntityId($r["enrollment_id"]),
            $r["version"],
            new EntityId($r["operation_id"]),
            $r["revision"],
            $effective,
            true,
        );
    }
    /** @param array<string, mixed> $result */
    private function asEnrollment(array $result): Enrollment
    {
        return new Enrollment(
            new EntityId($result["enrollment_id"]),
            new EntityId($result["student_id"]),
            new EntityId($result["section_id"]),
            new EntityId($result["term_id"]),
            RegistrationStatus::from($result["status"]),
            new DateTimeImmutable($result["registered_at"]),
            new DateTimeImmutable($result["status_at"]),
            $result["version"],
        );
    }
    /** @return array{person: EntityId, course: EntityId, term: EntityId} */
    private function routing(EntityId $student, EntityId $section): array
    {
        $s = $this->registration->section($section);
        return [
            "person" => $this->admissions->student($student)->personId,
            "course" => $s->courseId,
            "term" => $s->termId,
        ];
    }
    /** @param array{person: EntityId, course: EntityId, term: EntityId} $routing */
    private function lock(EntityId $student, EntityId $section, array $routing): void
    {
        $this->registration->lockStudent($student);
        $this->registration->lockPerson($routing["person"]);
        $this->registration->lockSection($section);
        $this->registration->lockCourse($routing["course"]);
        $this->policies->lockTerm($routing["term"]);
        $this->authorize($student);
    }
    /** @return array<string, mixed> */
    private function context(EntityId $student, EntityId $section, string $effective): array
    {
        $e =
        $this->registration->enrollment($student, $section) ??
        throw new RecordNotFoundException("Enrollment not found.");
        $now = $this->clock->now();
        $date = DateTimeImmutable::createFromFormat("!Y-m-d", $effective);
        if (
            !$e->status->isEnrolled() ||
            $date === false ||
            $date->format("Y-m-d") !== $effective ||
            $effective < $e->registeredAt->setTimezone($now->getTimezone())->format("Y-m-d") ||
            $effective > $now->format("Y-m-d")
        ) {
            throw new ValidationException([
                [
                    "field" => "enrollment_id",
                    "message" =>
                        "A current enrollment and an effective date from registration through today are required.",
                ],
            ]);
        }
        $credit = $this->drops->credit($e);
        $sectionRecord = $this->registration->section($section);
        if (
            $sectionRecord->termId->value !== $e->termId->value ||
            $credit["course_id"] !== $sectionRecord->courseId->value ||
            $credit["status"] !== $e->status->value
        ) {
            throw new ConcurrentModificationException("The course and academic record need registrar review.");
        }
        if ($credit["grade_id"] !== null || $credit["completed_milli"] !== 0 || $credit["grade_points_milli"] !== 0) {
            throw new ValidationException([
                ["field" => "enrollment_id", "message" => "Posted academic credit requires a grade-change review."],
            ]);
        }
        $s = $this->admissions->student($student);
        $p = $this->admissions->person($s->personId);
        return [
            "student_id" => $student->value,
            "section_id" => $section->value,
            "enrollment_id" => $e->id->value,
            "version" => $e->version,
            "given_name" => $p->givenName,
            "family_name" => $p->familyName,
            "institutional_id" => $p->institutionalId->value,
            "student_active" => $s->active,
            "section_code" => $this->registration->section($section)->code,
            "effective_on" => $effective,
            "timezone" => $now->getTimezone()->getName(),
            "credit" => $credit,
            "outcome" => $this->policy->outcome($e->termId, $effective),
        ];
    }
    /** @param array<string, mixed> $context */
    private function revision(array $context): string
    {
        return hash("sha256", json_encode($context, JSON_THROW_ON_ERROR));
    }
    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function replay(array $row, EntityId $actor, string $hash): array
    {
        if ($row["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("Access denied.");
        }
        if (!hash_equals($row["request_hash"], $hash)) {
            throw new ConcurrentModificationException("This reference belongs to another drop request.");
        }
        return json_decode($this->cipher->open($row["encrypted_content"]), true, 32, JSON_THROW_ON_ERROR)["result"];
    }
}
