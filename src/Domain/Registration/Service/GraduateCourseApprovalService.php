<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\AcademicRecord\Service\WorkPursuitService;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Advising\Service\AdvisorService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Event\GraduateCourseApprovalWasRecorded;
use Domain\Registration\GraduateCourseApprovals;
use Domain\Registration\Repository\GraduateApprovalState;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\ValueObject\GraduateCourseScope;
use Domain\Registration\ValueObject\GraduateRegistrationRules;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

final readonly class GraduateCourseApprovalService
{
    public const string ADVISOR = "sis:registration:graduate-advisor-approve";
    public const string REGISTRAR = "sis:registration:graduate-override";

    public function __construct(
        private GraduateAcademicContext $academic,
        private GraduateRegistrationGate $gate,
        private GraduateApprovalLedger $ledger,
        private GraduateApprovalState $state,
        private HistoricalEventStore $history,
        private RegistrationRepository $records,
        private AdmissionsRepository $admissions,
        private WorkPursuitService $pursuits,
        private AdvisorService $advisors,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
    ) {
    }

    public function view(EntityId $student, EntityId $section, ?EntityId $pursuit = null): array
    {
        $actor = $this->authorizeRead($student, $pursuit);
        return $this->transactions->run(function () use ($student, $section, $pursuit, $actor): array {
            $this->lock($student, $section);
            $selection = $this->pursuits->activeSelection($student, $pursuit);
            $offering = $this->records->section($section);
            $assessment = $this->gate->assess(
                $selection,
                $offering,
                $this->records->courseForSection($offering),
                self::budget(),
            );
            $root = $this->ledger->read($selection->id, self::budget());
            $current = $root->section($section);
            $this->authorizeRead($student, $selection->id, $actor);
            return [
                "pursuit_id" => $selection->id->value,
                "version" => $root->playhead(),
                "required" => $assessment["required"],
                "checks" => $assessment["checks"],
                "can_advisor" => $this->can($student, $selection->id, "advisor_approve"),
                "can_registrar" => $this->can($student, $selection->id, "registrar_override"),
                "advisor_recorded" => $current["advisor"] !== null,
                "registrar_recorded" => $current["registrar"] !== null,
            ];
        });
    }

    public function review(
        EntityId $student,
        EntityId $section,
        EntityId $pursuit,
        string $action,
        int $version,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize($student, $pursuit, $action);
        $reason = GraduateRegistrationRules::reason($reason);
        return $this->transactions->run(function () use (
            $student,
            $section,
            $pursuit,
            $action,
            $version,
            $reason,
            $actor,
        ): array {
            $this->lock($student, $section);
            $payload = $this->proposal(
                $student,
                $section,
                $pursuit,
                $action,
                $version,
                EntityId::generate(),
                $actor,
                $reason,
            );
            $this->authorize($student, $pursuit, $action, $actor);
            return [
                "operation_id" => $payload["operation_id"],
                "action" => $action,
                "version" => $version,
                "reason" => $reason,
                "revision" => $payload["intent"],
                "effective_at" => $payload["effective_at"],
                "review_token" => $this->proofs->issue(
                    "graduate-course-approval",
                    $action,
                    $pursuit,
                    $actor,
                    new EntityId($payload["operation_id"]),
                    GraduateRegistrationRules::hash([$payload["intent"], $version]),
                ),
            ];
        });
    }

    public function confirm(
        EntityId $student,
        EntityId $section,
        EntityId $pursuit,
        EntityId $operation,
        string $action,
        int $version,
        #[\SensitiveParameter] string $reason,
        string $revision,
        string $effectiveAt,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize($student, $pursuit, $action);
        $reason = GraduateRegistrationRules::reason($reason);
        return $this->transactions->run(function () use (
            $student,
            $section,
            $pursuit,
            $operation,
            $action,
            $version,
            $reason,
            $revision,
            $token,
            $actor,
            $effectiveAt,
        ): array {
            $person = $this->lock($student, $section);
            $this->roles->lock();
            $root = $this->ledger->read($pursuit, self::budget());
            $known = $root->record($operation);
            $proof = GraduateRegistrationRules::hash([$revision, $version]);
            $this->proofs->validate(
                $token,
                "graduate-course-approval",
                $action,
                $pursuit,
                $actor,
                $operation,
                $proof,
                $known !== null,
            );
            if ($known !== null) {
                if (
                    $known["scope"]["student_id"] !== $student->value ||
                    $known["scope"]["section_id"] !== $section->value ||
                    $known["effective_at"] !== $effectiveAt ||
                    $known["action"] !== $action ||
                    $known["actor_id"] !== $actor->value ||
                    $known["intent"] !== $revision ||
                    $known["position"] !== $version + 1 ||
                    GraduateCourseApprovals::intent(
                        array_intersect_key($known, array_flip(GraduateCourseApprovals::FIELDS)),
                        $reason,
                    ) !== $revision
                ) {
                    throw new AccessDeniedException();
                }
                $this->authorize($student, $pursuit, $action, $actor);
                return [
                    "status" => "recorded",
                    "operation_id" => $operation->value,
                    "version" => $known["position"],
                ];
            }
            $payload = $this->proposal(
                $student,
                $section,
                $pursuit,
                $action,
                $version,
                $operation,
                $actor,
                $reason,
                $effectiveAt,
            );
            if (!hash_equals($revision, $payload["intent"])) {
                throw new ConcurrentModificationException(
                    trans("Graduate-course eligibility changed. Review this decision again."),
                );
            }
            $this->audit->record($actor, "registration.graduate_" . $action, $operation);
            $fresh = $this->proposal(
                $student,
                $section,
                $pursuit,
                $action,
                $version,
                $operation,
                $actor,
                $reason,
                $effectiveAt,
            );
            if ($fresh !== $payload) {
                throw new ConcurrentModificationException(
                    trans("Graduate-course eligibility changed. Review this decision again."),
                );
            }
            $this->authorize($student, $pursuit, $action, $actor);
            $this->proofs->validate(
                $token,
                "graduate-course-approval",
                $action,
                $pursuit,
                $actor,
                $operation,
                $proof,
            );
            $event = GraduateCourseApprovalWasRecorded::occur(
                GraduateApprovalLedger::stream($pursuit)->aggregateId,
                $payload,
                ["__aggregate_type" => "graduate_course_approvals"],
            )->withPlayhead($version + 1);
            $this->history->append(
                GraduateApprovalLedger::stream($pursuit),
                $operation,
                $version,
                new EventContext(
                    $actor,
                    new \DateTimeImmutable($payload["effective_at"]),
                    $operation,
                    reason: $reason,
                ),
                [$event],
            );
            $this->state->advance($pursuit, $version);
            $this->ledger->read($pursuit, self::budget());
            $this->authorize($student, $pursuit, $action, $actor);
            if ($this->admissions->student($student)->personId->value !== $person->value) {
                throw new ConcurrentModificationException();
            }
            $this->proofs->validate($token, 'graduate-course-approval', $action, $pursuit, $actor, $operation, $proof);
            return ["status" => "recorded", "operation_id" => $operation->value, "version" => $version + 1];
        });
    }

    private function proposal(
        EntityId $student,
        EntityId $section,
        EntityId $pursuit,
        string $action,
        int $version,
        EntityId $operation,
        EntityId $actor,
        string $reason,
        ?string $effectiveAt = null,
    ): array {
        $selection = $this->pursuits->activeSelection($student, $pursuit);
        $root = $this->ledger->read($pursuit, self::budget());
        if ($root->playhead() !== $version || $version >= 2000) {
            throw new ConcurrentModificationException(
                trans("The graduate-course approvals changed. Reload the record."),
            );
        }
        $state = $root->section($section);
        $advisorOperation = null;
        if (str_ends_with($action, "_revoke")) {
            $old = $state[$action === "advisor_revoke" ? "advisor" : "registrar"];
            if ($old === null) {
                throw new \DomainException(trans("There is no current decision to revoke."));
            }
            $scope = new GraduateCourseScope($old["scope"]);
            $advisorOperation = $old["advisor_operation_id"] ?? null;
        } else {
            $offering = $this->records->section($section);
            $scope = $this->academic->read(
                $selection,
                $offering,
                $this->records->courseForSection($offering),
                self::budget(),
            );
            if ($scope === null) {
                throw new \DomainException(
                    trans("This registration does not require an undergraduate graduate-course approval."),
                );
            }
            if ($action === "registrar_override") {
                $advisor = $state["advisor"];
                if (
                    $advisor === null ||
                    new GraduateCourseScope($advisor["scope"])->binding() !== $scope->binding()
                ) {
                    throw new \DomainException(
                        trans("Current advisor approval is required before the registrar override."),
                    );
                }
                $advisorOperation = $advisor["operation_id"];
            }
        }
        $payload = [
            "operation_id" => $operation->value,
            "action" => $action,
            "scope" => $scope->data,
            "actor_id" => $actor->value,
            "effective_at" =>
                $effectiveAt ??
                $this->clock->now()->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d\TH:i:s.u\Z"),
            "advisor_operation_id" => $advisorOperation,
            "before_hash" => GraduateRegistrationRules::hash($state),
        ];
        return $payload + ["intent" => GraduateCourseApprovals::intent($payload, $reason)];
    }

    private function lock(EntityId $student, EntityId $section): EntityId
    {
        $initial = $this->admissions->student($student);
        $this->records->lockStudent($student);
        $this->records->lockPerson($initial->personId);
        $this->records->lockSection($section);
        $this->records->lockCourse($this->records->section($section)->courseId);
        if ($this->admissions->student($student)->personId->value !== $initial->personId->value) {
            throw new ConcurrentModificationException();
        }
        return $initial->personId;
    }

    private function authorizeRead(
        EntityId $student,
        ?EntityId $pursuit,
        ?EntityId $expected = null,
    ): EntityId {
        $actor = $this->access->authenticatedUserId();
        $person = $this->admissions->person($this->admissions->student($student)->personId);
        try {
            $actual = $this->access->requireOwnerOrPermission($person->ownerUserId, "sis:student:read");
        } catch (AccessDeniedException) {
            $selection = $this->pursuits->activeSelection($student, $pursuit);
            $actual = $this->advisors->requireAdvisor($student, $selection->id);
        }
        if ($actual->value !== $actor->value || ($expected !== null && $expected->value !== $actor->value)) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function can(EntityId $student, EntityId $pursuit, string $action): bool
    {
        try {
            $this->authorize($student, $pursuit, $action);
            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    private function authorize(
        EntityId $student,
        EntityId $pursuit,
        string $action,
        ?EntityId $expected = null,
    ): EntityId {
        if (!in_array($action, GraduateCourseApprovals::ACTIONS, true)) {
            throw new AccessDeniedException();
        }
        $advisor = str_starts_with($action, "advisor_");
        $actor = $this->access->requirePermission($advisor ? self::ADVISOR : self::REGISTRAR);
        $other = $advisor
        ? $this->advisors->requireAdvisor($student, $pursuit)
        : $this->access->requirePermission("sis:registration:manage");
        if ($other->value !== $actor->value || ($expected !== null && $expected->value !== $actor->value)) {
            throw new AccessDeniedException();
        }
        if (!$advisor) {
            if ($this->access->requirePermission("sis:student:read")->value !== $actor->value) {
                throw new AccessDeniedException();
            }
        }
        return $actor;
    }

    private static function budget(): HistoryReadBudget
    {
        return new HistoryReadBudget(maximumEvents: 6000, maximumBytes: 8_388_608, maximumMilliseconds: 5000);
    }
}
