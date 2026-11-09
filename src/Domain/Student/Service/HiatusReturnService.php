<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\HiatusRepository;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\StudentHiatus;
use Domain\Student\ValueObject\HiatusReturnPlan;

final readonly class HiatusReturnService
{
    public function __construct(
        private HiatusRepository $history,
        private AdmissionsRepository $admissions,
        private StudentRecordRepository $students,
        private RegistrationRepository $registration,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private StudentActivityLedger $activity,
    ) {
    }

    /** @return array<string, mixed> */
    public function review(EntityId $student, EntityId $hiatus, DateTimeImmutable $on): array
    {
        $this->access->requirePermission("sis:registration:manage");
        $person = $this->admissions->student($student)->personId;
        return $this->transactions->run(function () use ($student, $hiatus, $on, $person): array {
            $current = $this->lock($student, $hiatus, $person);
            $context = $this->context($current, $on);
            return $context + [
                "revision" => $this->revision($context),
                "operation_id" => EntityId::generate()->value,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function commit(
        EntityId $student,
        EntityId $hiatus,
        HiatusReturnPlan $plan,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->access->requirePermission("sis:registration:manage");
        $note = trim($reason);
        if (
            !mb_check_encoding($note, "UTF-8") ||
            $note === "" ||
            mb_strlen($note) > 10000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note)
        ) {
            throw new ValidationException([
                ["field" => "note", "message" => "Enter a reason of up to 10,000 characters."],
            ]);
        }
        $request = [
            "on" => $plan->on->format("Y-m-d"),
            "revision" => $plan->revision,
            "reactivate" => $plan->reactivate,
            "note" => $note,
        ];
        $person = $this->admissions->student($student)->personId;
        return $this->transactions->run(function () use (
            $student,
            $hiatus,
            $plan,
            $actor,
            $request,
            $person,
        ): array {
            $current = $this->lock($student, $hiatus, $person);
            $previous = $this->history->action($plan->operation);
            if ($previous !== null) {
                if (
                    $previous["hiatus_id"] !== $hiatus->value ||
                    $previous["action"] !== "returned" ||
                    $previous["actor_id"] !== $actor->value ||
                    $this->payload($previous)["request"] !== $request
                ) {
                    throw new ConcurrentModificationException(
                        "The operation belongs to another return request.",
                    );
                }
                return $this->receipt($previous);
            }
            $context = $this->context($current, $plan->on);
            if (!hash_equals($this->revision($context), $plan->revision)) {
                throw new ConcurrentModificationException("The return context changed. Review it again.");
            }
            $next = $current->withEnd(new DateTimeImmutable($context["hiatus_ends_on"]));
            $this->history->save($next, $current->version);
            $result = [
                "return_on" => $context["return_on"],
                "hiatus_ends_on" => $context["hiatus_ends_on"],
                "student_active" => $plan->reactivate || $context["student"]["active"],
                "activity_changed" => $plan->reactivate && !$context["student"]["active"],
                "staff_registration_blocked" => $context["staff_registration_blocked"],
                "self_registration_blocked" => $context["self_registration_blocked"],
                "enrollments_restored" => false,
                "financial_changes" => false,
            ];
            if ($result["activity_changed"]) {
                $this->activity->decide(
                    $this->admissions->student($student),
                    true,
                    $request["on"],
                    "hiatus_return",
                    $hiatus,
                    \Domain\Gradebook\ValueObject\PublishedGradeId::forVersion(
                        $plan->operation,
                        "activity.hiatus_return",
                    ),
                    $actor,
                    $request["note"],
                    true,
                );
                $this->audit->record($actor, "student.activity_updated", $student);
            }
            $encrypted = $this->cipher->seal(
                json_encode(
                    [
                        "request" => $request,
                        "previous_ends_on" => $current->dates->end?->format("Y-m-d"),
                        "new_ends_on" => $context["hiatus_ends_on"],
                        "return_result" => $result,
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            );
            $this->history->addAction(
                $plan->operation,
                $hiatus,
                $actor,
                "returned",
                $encrypted,
                $this->clock
                    ->now()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format(DATE_ATOM),
            );
            $this->audit->record($actor, "student.hiatus_returned", $hiatus);
            return [
                "operation_id" => $plan->operation->value,
                "hiatus_id" => $hiatus->value,
                "result" => $result,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function saved(EntityId $student, EntityId $hiatus, EntityId $operation): array
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        $this->owned($student, $hiatus);
        $row = $this->history->action($operation);
        if ($row === null || $row["hiatus_id"] !== $hiatus->value || $row["action"] !== "returned") {
            throw new RecordNotFoundException("Return case not found.");
        }
        $this->audit->record($actor, "student.hiatus_return_read", $hiatus);
        return $this->receipt($row);
    }

    private function lock(EntityId $student, EntityId $hiatus, EntityId $person): StudentHiatus
    {
        $this->registration->lockStudent($student);
        $this->registration->lockPerson($person);
        $this->history->lock($hiatus);
        $this->access->requirePermission("sis:registration:manage");
        return $this->owned($student, $hiatus);
    }

    private function owned(EntityId $student, EntityId $hiatus): StudentHiatus
    {
        $current = $this->history->find($hiatus);
        if ($current === null || $current->studentId->value !== $student->value) {
            throw new RecordNotFoundException("Hiatus record not found.");
        }
        return $current;
    }

    /** @return array<string, mixed> */
    private function context(StudentHiatus $hiatus, DateTimeImmutable $on): array
    {
        $date = $on->format("Y-m-d");
        $today = $this->clock->now()->format("Y-m-d");
        if ($hiatus->voided || $this->history->returned($hiatus->id) !== null) {
            throw new ValidationException([
                ["field" => "hiatus", "message" => "Use a non-voided hiatus without a return case."],
            ]);
        }
        if ($date <= $hiatus->dates->start->format("Y-m-d") || $date > $today) {
            throw new ValidationException([
                [
                    "field" => "return_on",
                    "message" => "Choose a return date after the hiatus start and no later than today.",
                ],
            ]);
        }
        $end = $on->modify("-1 day")->format("Y-m-d");
        $existingEnd = $hiatus->dates->end?->format("Y-m-d");
        if ($existingEnd !== null && $existingEnd < $end) {
            $end = $existingEnd;
        }
        $student = $this->admissions->student($hiatus->studentId);
        $activityVersion = $this->activity->verify($student);
        $person = $this->admissions->person($student->personId);
        // Return review discloses hold effects, not private restriction codes or notes.
        $staff = $this->registration->blockingRestriction($person->id, $this->clock->now(), true);
        $self = $this->registration->blockingRestriction($person->id, $this->clock->now(), false);
        return [
            "activity_version" => $activityVersion,
            "student" => [
                "id" => $student->id->value,
                "given_name" => $person->givenName,
                "family_name" => $person->familyName,
                "institutional_id" => $person->institutionalId->value,
                "active" => $student->active,
            ],
            "primary_program" => $this->students->program($student->programId),
            "entry_term" => $this->students->term($student->entryTermId),
            "hiatus_id" => $hiatus->id->value,
            "hiatus_revision" => $hiatus->revision(),
            "hiatus_label" => $hiatus->code->label(),
            "hiatus_starts_on" => $hiatus->dates->start->format("Y-m-d"),
            "hiatus_ends_on" => $end,
            "return_on" => $date,
            "today" => $today,
            "timezone" => $this->clock->now()->getTimezone()->getName(),
            "staff_registration_blocked" => $staff !== null,
            "self_registration_blocked" => $self !== null,
            "hold_revision" => hash(
                "sha256",
                json_encode([$staff?->id->value, $self?->id->value], JSON_THROW_ON_ERROR),
            ),
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
    private function payload(#[\SensitiveParameter] array $row): array
    {
        return json_decode($this->cipher->open($row["encrypted_content"]), true, 16, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function receipt(#[\SensitiveParameter] array $row): array
    {
        return [
            "operation_id" => $row["id"],
            "hiatus_id" => $row["hiatus_id"],
            "result" => $this->payload($row)["return_result"],
        ];
    }
}
