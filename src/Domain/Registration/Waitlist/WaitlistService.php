<?php

declare(strict_types=1);

namespace Domain\Registration\Waitlist;

use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Service\RegistrationWindowService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentActivityLedger;
use Domain\Student\Service\StudentPrimaryProgramLedger;

final readonly class WaitlistService
{
    public const string DUTY = "sis:registration:waitlist";
    public function __construct(
        private WaitlistLedger $ledger,
        private WaitlistRepository $records,
        private RegistrationRepository $registration,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
        private RegistrationWindowService $windows,
        private StudentActivityLedger $activity,
        private StudentPrimaryProgramLedger $programs,
    ) {
    }
    public function policy(EntityId $section): array
    {
        $actor = $this->policyAuthority();
        $this->registration->section($section);
        $root = $this->ledger->load($section, WaitlistLedger::budget());
        $this->policyAuthority($actor);
        return [
            "policy" => $root->policy(),
            "version" => $root->playhead(),
            "waiting_count" => count($root->active()),
        ];
    }
    public function reviewPolicy(
        EntityId $section,
        array $policy,
        int $version,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->policyAuthority();
        $reason = $this->reason($reason);
        $policy = WaitlistPolicy::from($policy)->describe();
        $current = $this->policy($section);
        if ($current["version"] !== $version) {
            throw new ConcurrentModificationException(
                "The waitlist changed. Review its policy again.",
            );
        }
        if ($version >= 1900) {
            throw new ValidationException([
                [
                    "field" => "policy",
                    "message" =>
                        "The waitlist policy history reached its bounded allowance. " .
                        "Existing places retain resolution capacity.",
                ],
            ]);
        }
        $operation = EntityId::generate();
        $intent = SectionWaitlist::intent(
            ["operation_id" => $operation->value, "policy" => $policy],
            $reason,
        );
        $this->policyAuthority($actor);
        return [
            "operation_id" => $operation->value,
            "policy" => $policy,
            "version" => $version,
            "reason" => $reason,
            "waiting_count" => $current["waiting_count"],
            "review_token" => $this->proofs->issue(
                "waitlist",
                "policy",
                $section,
                $actor,
                $operation,
                WaitlistPolicy::hash([$intent, $version]),
            ),
        ];
    }
    public function confirmPolicy(
        EntityId $section,
        EntityId $operation,
        array $policy,
        int $version,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->policyAuthority();
        $reason = $this->reason($reason);
        $policy = WaitlistPolicy::from($policy)->describe();
        $payload = ["operation_id" => $operation->value, "policy" => $policy];
        $intent = SectionWaitlist::intent($payload, $reason);
        return $this->transactions->run(function () use (
            $section,
            $operation,
            $version,
            $reason,
            $token,
            $actor,
            $payload,
            $intent,
        ): array {
            $this->roles->lock();
            $this->registration->lockSection($section);
            $budget = WaitlistLedger::budget();
            $root = $this->ledger->load($section, $budget);
            $accepted = $root->receipt($operation);
            $revision = WaitlistPolicy::hash([$intent, $version]);
            $this->proofs->validate(
                $token,
                "waitlist",
                "policy",
                $section,
                $actor,
                $operation,
                $revision,
                $accepted !== null,
            );
            if ($accepted !== null) {
                $this->sameReceipt($accepted, $actor, $intent);
                $this->policyAuthority($actor);
                return $this->result($operation, $accepted);
            }
            if ($root->playhead() !== $version || $version >= 1900) {
                throw new ConcurrentModificationException(
                    "The waitlist changed. Review its policy again.",
                );
            }
            $this->audit->record($actor, "waitlist.policy_set", $section);
            $this->policyAuthority($actor);
            $this->proofs->validate(
                $token,
                "waitlist",
                "policy",
                $section,
                $actor,
                $operation,
                $revision,
            );
            if ($this->ledger->load($section, $budget)->playhead() !== $version) {
                throw new ConcurrentModificationException(
                    "The waitlist changed before its policy was accepted.",
                );
            }
            $this->ledger->append(
                $section,
                $root,
                $payload,
                true,
                new EventContext($actor, $this->clock->now(), $operation, reason: $reason),
            );
            $this->policyAuthority($actor);
            $this->proofs->validate(
                $token,
                "waitlist",
                "policy",
                $section,
                $actor,
                $operation,
                $revision,
            );
            return $this->result($operation, $root->receipt($operation));
        });
    }
    public function view(EntityId $student, EntityId $section, bool $staff): array
    {
        $actor = $this->authorize($student, $staff);
        $root = $this->ledger->load($section, WaitlistLedger::budget());
        $s = $this->registration->section($section);
        $course = $this->registration->courseForSection($s);
        $mine = $root->forStudent($student->value);
        $rank =
        $mine === null
        ? null
        : array_search($mine["id"], array_column($root->active(), "id"), true) + 1;
        $this->authorize($student, $staff, $actor);
        return [
            "section_id" => $section->value,
            "section_code" => $s->code,
            "course_code" => $course->code,
            "policy" => $root->policy(),
            "waiting_count" => count($root->active()),
            "entry_id" => $mine["id"] ?? null,
            "rank" => $rank,
            "seats_remaining" => max(0, $s->capacity - $this->registration->sectionCount($section)),
        ];
    }
    public function listing(EntityId $section, int $after = 0): array
    {
        $actor = $this->staff();
        if ($after < 0) {
            throw new ValidationException([
                ["field" => "after", "message" => "Use a valid queue position."],
            ]);
        }
        $s = $this->registration->section($section);
        $root = $this->ledger->load($section, WaitlistLedger::budget());
        $active = $root->active();
        $rows = array_values(
            array_filter($active, static fn(array $e): bool => $e["position"] > $after),
        );
        $items = [];
        foreach (array_slice($rows, 0, 20) as $entry) {
            $person = $this->admissions->person(new EntityId($entry["person_id"]));
            $student = $this->admissions->student(new EntityId($entry["student_id"]));
            if ($student->personId->value !== $person->id->value) {
                throw new CorruptHistoryException();
            }
            $items[] = [
                "entry_id" => $entry["id"],
                "student_id" => $entry["student_id"],
                "current_name" => $person->givenName . " " . $person->familyName,
                "current_institutional_id" => $person->institutionalId->value,
                "accepted_position" => $entry["position"],
                "rank" => array_search($entry["id"], array_column($active, "id"), true) + 1,
            ];
        }
        $this->audit->record($actor, "waitlist.queue_read", $section);
        $this->staff($actor);
        return [
            "section_id" => $section->value,
            "section_code" => $s->code,
            "policy" => $root->policy(),
            "waiting_count" => count($active),
            "seats_remaining" => max(0, $s->capacity - $this->registration->sectionCount($section)),
            "items" => $items,
            "next_after" => count($rows) > 20 ? $items[19]["accepted_position"] : null,
        ];
    }
    public function mine(EntityId $student, ?EntityId $after = null): array
    {
        $actor = $this->authorize($student, false);
        $rows = $this->records->mine($student, $after);
        $budget = WaitlistLedger::budget();
        $items = [];
        foreach (array_slice($rows, 0, 10) as $row) {
            $section = new EntityId($row["section_id"]);
            $root = $this->ledger->load($section, $budget);
            $entry = $root->entries()[$row["id"]] ?? throw new CorruptHistoryException();
            $expected = $entry;
            unset($expected["changed_at"]);
            $actual = $row;
            unset($actual["section_id"]);
            if (
                $entry["student_id"] !== $student->value ||
                $entry["person_id"] !== $this->admissions->student($student)->personId->value ||
                !hash_equals(WaitlistPolicy::hash($expected), WaitlistPolicy::hash($actual))
            ) {
                throw new CorruptHistoryException();
            }
            $s = $this->registration->section($section);
            $course = $this->registration->courseForSection($s);
            $items[] = [
                "entry_id" => $entry["id"],
                "section_id" => $section->value,
                "section_code" => $s->code,
                "course_code" => $course->code,
                "status" => $entry["status"],
                "rank" =>
                    $entry["status"] === "active"
                        ? array_search($entry["id"], array_column($root->active(), "id"), true) + 1
                        : null,
            ];
        }
        $this->authorize($student, false, $actor);
        return ["items" => $items, "next_after" => count($rows) > 10 ? $rows[9]["id"] : null];
    }
    public function review(
        EntityId $student,
        EntityId $section,
        string $action,
        bool $staff,
        ?EntityId $expectedEntry,
        #[\SensitiveParameter] ?string $reason = null,
    ): array {
        $actor = $this->authorize($student, $staff);
        $this->action($action, $staff);
        $reason =
        $action === "removed" ? $this->reason($reason ?? "") : $this->standardReason($action);
        return $this->transactions->run(function () use (
            $student,
            $section,
            $action,
            $staff,
            $expectedEntry,
            $reason,
            $actor,
        ): array {
            $this->lock($student, $section);
            $this->authorize($student, $staff, $actor);
            $root = $this->ledger->load($section, WaitlistLedger::budget());
            if (
                ($action === "requested") !== ($expectedEntry === null) ||
                ($expectedEntry !== null &&
                    ($root->forStudent($student->value)["id"] ?? null) !== $expectedEntry->value)
            ) {
                throw new ConcurrentModificationException(
                    "The selected waitlist request changed. Refresh it.",
                );
            }
            $operation = EntityId::generate();
            [$payload, $revision] = $this->change(
                $student,
                $section,
                $action,
                $operation,
                $root,
                $staff,
                $reason,
            );
            $this->authorize($student, $staff, $actor);
            return [
                "operation_id" => $operation->value,
                "entry_id" => $payload["entry_id"],
                "revision" => $revision,
                "action" => $action,
                "reason" => $reason,
                "review_token" => $this->proofs->issue(
                    "waitlist",
                    $staff ? "staff" : "owner",
                    $section,
                    $actor,
                    $operation,
                    $revision,
                ),
            ];
        });
    }
    public function confirm(
        EntityId $student,
        EntityId $section,
        EntityId $operation,
        string $action,
        bool $staff,
        string $revision,
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] ?string $reason = null,
    ): array {
        $actor = $this->authorize($student, $staff);
        $this->action($action, $staff);
        $reason =
        $action === "removed" ? $this->reason($reason ?? "") : $this->standardReason($action);
        return $this->transactions->run(function () use (
            $student,
            $section,
            $operation,
            $action,
            $staff,
            $revision,
            $token,
            $reason,
            $actor,
        ): array {
            $this->lock($student, $section);
            $this->authorize($student, $staff, $actor);
            $budget = WaitlistLedger::budget();
            $root = $this->ledger->load($section, $budget);
            $accepted = $root->receipt($operation);
            $this->proofs->validate(
                $token,
                "waitlist",
                $staff ? "staff" : "owner",
                $section,
                $actor,
                $operation,
                $revision,
                $accepted !== null,
            );
            if ($accepted !== null) {
                $entry =
                    $root->entries()[$accepted["entry_id"]] ?? throw new CorruptHistoryException();
                $payload = [
                    "operation_id" => $operation->value,
                    "action" => $action,
                    "entry_id" => $entry["id"],
                    "student_id" => $student->value,
                    "person_id" => $this->admissions->student($student)->personId->value,
                    "policy_position" => $entry["policy_position"],
                    "enrollment_id" => null,
                ];
                $this->sameReceipt($accepted, $actor, SectionWaitlist::intent($payload, $reason));
                $this->authorize($student, $staff, $actor);
                return $this->result($operation, $accepted);
            }
            [$payload, $current] = $this->change(
                $student,
                $section,
                $action,
                $operation,
                $root,
                $staff,
                $reason,
            );
            if (!hash_equals($revision, $current)) {
                throw new ConcurrentModificationException(
                    "The waitlist request or section policy changed. Review again.",
                );
            }
            $this->audit->record($actor, "waitlist." . $action, new EntityId($payload["entry_id"]));
            $this->authorize($student, $staff, $actor);
            $this->proofs->validate(
                $token,
                "waitlist",
                $staff ? "staff" : "owner",
                $section,
                $actor,
                $operation,
                $revision,
            );
            $fresh = $this->ledger->load($section, $budget);
            [, $current] = $this->change(
                $student,
                $section,
                $action,
                $operation,
                $fresh,
                $staff,
                $reason,
            );
            if (!hash_equals($revision, $current)) {
                throw new ConcurrentModificationException(
                    "The waitlist source changed before completion. Review again.",
                );
            }
            $this->ledger->append(
                $section,
                $fresh,
                $payload,
                false,
                new EventContext($actor, $this->clock->now(), $operation, reason: $reason),
            );
            $this->authorize($student, $staff, $actor);
            $this->proofs->validate(
                $token,
                "waitlist",
                $staff ? "staff" : "owner",
                $section,
                $actor,
                $operation,
                $revision,
            );
            return $this->result($operation, $fresh->receipt($operation));
        });
    }
    private function change(
        EntityId $student,
        EntityId $section,
        string $action,
        EntityId $operation,
        SectionWaitlist $root,
        bool $staff,
        string $reason,
    ): array {
        $s = $this->admissions->student($student);
        $entry = $root->forStudent($student->value);
        $setup = null;
        if ($action === "requested") {
            $this->activity->verify($s);
            $this->programs->verify($s);
            $offering = $this->registration->section($section);
            $this->registration->lockCourse($offering->courseId);
            $course = $this->registration->courseForSection($offering);
            $window = $this->windows->checked(
                $offering->termId,
                $this->registration->window($offering->termId),
            );
            if (
                !$root->policy()["enabled"] ||
                count($root->active()) >= $root->policy()["maximum_active"] ||
                $root->playhead() >= 1800 ||
                $entry !== null ||
                !$s->active ||
                !$offering->active ||
                !$course->active ||
                (!$staff &&
                    (!$offering->webRegistration || !$window->isOpen($this->clock->now()))) ||
                $this->registration->enrollment($student, $section) !== null ||
                ($root->active() === [] &&
                    $this->registration->sectionCount($section) < $offering->capacity)
            ) {
                throw new ValidationException([
                    [
                        "field" => "waitlist",
                        "message" =>
                            "Waitlisting is unavailable, already requested, or a seat is available " .
                            "for ordinary registration. Closed enrollments need registrar review.",
                    ],
                ]);
            }
            $entry = [
                "id" => $operation->value,
                "student_id" => $student->value,
                "person_id" => $s->personId->value,
                "policy_position" => $root->policyPosition(),
            ];
            $setup = [
                $root->policyPosition(),
                $offering->termId->value,
                $offering->courseId->value,
                $offering->webRegistration,
                $offering->active,
                $course->active,
                $window->fingerprint(),
            ];
        }
        if ($entry === null || $entry["person_id"] !== $s->personId->value) {
            throw new ConcurrentModificationException(
                "The active waitlist request changed. Refresh it.",
            );
        }
        $payload = [
            "operation_id" => $operation->value,
            "action" => $action,
            "entry_id" => $entry["id"],
            "student_id" => $student->value,
            "person_id" => $s->personId->value,
            "policy_position" => $entry["policy_position"],
            "enrollment_id" => null,
        ];
        return [
            $payload,
            WaitlistPolicy::hash([$section->value, $payload, $setup, $staff, $reason]),
        ];
    }
    private function lock(EntityId $student, EntityId $section): void
    {
        $this->roles->lock();
        $this->registration->lockStudent($student);
        $this->registration->lockPerson($this->admissions->student($student)->personId);
        $this->registration->lockSection($section);
    }
    private function authorize(EntityId $student, bool $staff, ?EntityId $expected = null): EntityId
    {
        $actor = $staff ? $this->staff() : $this->access->authenticatedUserId();
        $s = $this->admissions->student($student);
        if (
            !$staff &&
            $this->admissions->person($s->personId)->ownerUserId?->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException();
        }
        return $actor;
    }
    private function staff(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission(self::DUTY);
        if (
            $this->access->requirePermission("sis:registration:manage")->value !== $actor->value ||
            ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }
    private function policyAuthority(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission("sis:registration:policy");
        if (
            $this->access->requirePermission("sis:registration:manage")->value !== $actor->value ||
            ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }
    private function action(string $action, bool $staff): void
    {
        if (!in_array($action, ["requested", "cancelled", ...$staff ? ["removed"] : []], true)) {
            throw new ValidationException([
                [
                    "field" => "action",
                    "message" => "Choose join, withdraw or an authorized removal.",
                ],
            ]);
        }
    }
    private function reason(#[\SensitiveParameter] string $reason): string
    {
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            trim($reason) === "" ||
            mb_strlen($reason) > 1000 ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $reason)
        ) {
            throw new ValidationException([
                [
                    "field" => "reason",
                    "message" => "Provide a reason of up to one thousand characters.",
                ],
            ]);
        }
        return trim($reason);
    }
    private function standardReason(string $action): string
    {
        return $action === "requested"
        ? "An authorized caller requested a place on this section waitlist."
        : "An authorized caller withdrew this section waitlist request.";
    }
    private function sameReceipt(array $accepted, EntityId $actor, string $intent): void
    {
        if ($accepted["actor_id"] !== $actor->value || !hash_equals($accepted["intent"], $intent)) {
            throw new AccessDeniedException();
        }
    }
    private function result(EntityId $operation, array $accepted): array
    {
        return [
            "status" => "recorded",
            "operation_id" => $operation->value,
            "entry_id" => $accepted["entry_id"],
            "action" => $accepted["action"],
            "accepted_position" => $accepted["version"],
        ];
    }
}
