<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Service;

use Domain\AcademicRecord\AcademicCredit;
use Domain\AcademicRecord\Event\RegisteredCreditsWereCorrected;
use Domain\AcademicRecord\Repository\AcademicCreditRepository;
use Domain\AcademicRecord\ValueObject\AcademicCreditId;
use Domain\AcademicRecord\ValueObject\RegisteredCreditCorrection;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Service\EnrollmentLedger;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;

/** Registrar correction of recorded load; original registration, award and financial facts stay distinct. */
final readonly class RegisteredCreditService
{
    public function __construct(
        private InstitutionalCreditLedger $credits,
        private EnrollmentLedger $enrollments,
        private AcademicCreditRepository $records,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
    ) {
    }
    public function view(EntityId $student, EntityId $section, EntityId $enrollment): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $actor,
        ): array {
            $this->lock($student, $section, $actor);
            [$id, $root, $facts, $registration] = $this->source(
                $student,
                $section,
                $enrollment,
                $this->budget(),
            );
            $term = $this->records->term(new EntityId($registration["facts"]["term_id"]));
            $this->sameActor($actor);
            return $this->summary($root, $facts) + [
                "term_code" => $term["code"],
                "expected_version" => $facts["version"],
                "expected_position" => $root->playhead(),
                "can_correct" => $this->eligible($root, $registration),
            ];
        });
    }
    public function review(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        int $version,
        int $position,
        int $milli,
        #[\SensitiveParameter] string $reason,
        bool $acknowledged,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason, $acknowledged);
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $version,
            $position,
            $milli,
            $reason,
            $acknowledged,
            $actor,
        ): array {
            $this->lock($student, $section, $actor);
            [, $root, $facts, $registration] = $this->source(
                $student,
                $section,
                $enrollment,
                $this->budget(),
            );
            $this->fresh($root, $facts, $registration, $version, $position, $milli);
            $before = $root->registeredCredits()["attempted_milli"];
            $op = EntityId::generate();
            $intent = $this->intent(
                $student,
                $section,
                $enrollment,
                $version,
                $position,
                $before,
                $milli,
                $reason,
                $acknowledged,
            );
            $this->sameActor($actor);
            return $this->summary($root, $facts) + [
                "operation_id" => $op->value,
                "expected_version" => $version,
                "expected_position" => $position,
                "previous_registered_milli" => $before,
                "registered_milli" => $milli,
                "reason" => $reason,
                "acknowledged" => $acknowledged,
                "review_token" => $this->proofs->issue(
                    "registered-credits",
                    "staff",
                    $enrollment,
                    $actor,
                    $op,
                    $intent,
                ),
            ];
        });
    }
    public function confirm(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        EntityId $operation,
        int $version,
        int $position,
        int $previousMilli,
        int $milli,
        #[\SensitiveParameter] string $reason,
        bool $acknowledged,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason, $acknowledged);
        $intent = $this->intent(
            $student,
            $section,
            $enrollment,
            $version,
            $position,
            $previousMilli,
            $milli,
            $reason,
            $acknowledged,
        );
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $operation,
            $version,
            $position,
            $previousMilli,
            $milli,
            $reason,
            $actor,
            $intent,
            $token,
        ): array {
            $this->lock($student, $section, $actor);
            $budget = $this->budget();
            [$id, $root, $facts, $registration] = $this->source(
                $student,
                $section,
                $enrollment,
                $budget,
                true,
            );
            $accepted = $root->registeredCreditCorrection($operation);
            $this->proofs->validate(
                $token,
                "registered-credits",
                "staff",
                $enrollment,
                $actor,
                $operation,
                $intent,
                $accepted !== null,
            );
            if ($accepted !== null) {
                if (
                    !hash_equals($accepted["intent"], $intent) ||
                    $accepted["receipt"]["first_version"] !== $position + 1 ||
                    $this->history->verifyOperation($operation) !== 1
                ) {
                    throw new ConcurrentModificationException(
                        "This operation belongs to another registration correction.",
                    );
                }
                $this->sameActor($actor);
                return $accepted["receipt"];
            }
            $this->fresh($root, $facts, $registration, $version, $position, $milli);
            if ($root->registeredCredits()["attempted_milli"] !== $previousMilli) {
                throw new ConcurrentModificationException(
                    "The registered credits changed. Review again.",
                );
            }
            $event = RegisteredCreditsWereCorrected::occur(
                new AcademicCreditId($id->value),
                [
                    "decision_id" => $operation->value,
                    "previous_decision_id" => $root->decision(),
                    "previous_registered_milli" => $previousMilli,
                    "registered_milli" => $milli,
                ],
                ["__aggregate_type" => "academic_credit", "registered_credits_intent" => $intent],
            )->withPlayhead($position + 1);
            $root->acceptHistorical($event);
            $receipt = $this->history->append(
                $this->stream($id),
                $operation,
                $position,
                new EventContext(
                    $actor,
                    $this->clock->now(),
                    $operation,
                    new EntityId($event->payload()["previous_decision_id"]),
                    $reason,
                ),
                [$event],
            );
            $this->records->project(
                $root->credit() ?? throw new CorruptHistoryException(),
                $section,
                $version,
            );
            $this->audit->record($actor, "registration.credits_corrected", $id);
            [, $after, $projected, $finalRegistration] = $this->source(
                $student,
                $section,
                $enrollment,
                $budget,
            );
            if (
                $after->playhead() !== $position + 1 ||
                $after->registeredCredits()["attempted_milli"] !== $milli ||
                $projected !== array_replace($facts, ["version" => $version + 1]) ||
                $finalRegistration !== $registration
            ) {
                throw new CorruptHistoryException();
            }
            $this->proofs->validate(
                $token,
                "registered-credits",
                "staff",
                $enrollment,
                $actor,
                $operation,
                $intent,
            );
            $this->sameActor($actor);
            return $receipt;
        });
    }
    public function history(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        ?string $cursor = null,
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $cursor,
            $actor,
        ): array {
            $this->lock($student, $section, $actor);
            $budget = $this->budget();
            [$id] = $this->source($student, $section, $enrollment, $budget);
            $page = $budget->page($this->history, $this->stream($id), $cursor);
            $items = [];
            foreach ($page->events as $stored) {
                if (!($stored->event instanceof RegisteredCreditsWereCorrected)) {
                    continue;
                }
                $p = $stored->event->payload();
                $items[] = [
                    "previous_registered_milli" => $p["previous_registered_milli"],
                    "registered_milli" => $p["registered_milli"],
                    "version" => $stored->event->playhead(),
                    "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                    "effective_at" => $stored->context->describe()["effective_at"],
                ];
            }
            $this->sameActor($actor);
            return ["items" => $items, "next_cursor" => $page->nextCursor];
        });
    }
    private function source(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        HistoryReadBudget $budget,
        bool $allowRemoved = false,
    ): array {
        [$id, $root, $facts] = $this->credits->decisionRecord(
            $student,
            $section,
            $enrollment,
            $allowRemoved,
            $budget,
        );
        $registration = $this->enrollments->evaluationFacts($enrollment, $student, $budget);
        $e = $registration["facts"];
        if (
            $e["section_id"] !== $section->value ||
            $e["status"] !== $facts["status"] ||
            ($root->registrationTermId() ?? $facts["term_id"]) !== $e["term_id"]
        ) {
            throw new CorruptHistoryException();
        }
        return [$id, $root, $facts, $registration];
    }
    private function summary(AcademicCredit $root, array $facts): array
    {
        return [
            "current_registered_milli" => $root->registeredCredits()["attempted_milli"] ?? null,
            "original_registered_milli" =>
                $root->originalRegisteredCredits()["attempted_milli"] ?? null,
            "awarded_attempted_milli" => $facts["attempted_milli"],
            "awarded_completed_milli" => $facts["completed_milli"],
            "course_code" => $root->registeredCredits()["course_code"] ?? $facts["course_code"],
            "course_title" => $root->registeredCredits()["course_title"] ?? $facts["course_title"],
        ];
    }
    private function eligible(AcademicCredit $root, array $registration): bool
    {
        return $root->canCorrectRegisteredCredits() &&
        $registration["history_recorded"] &&
        !$registration["earlier_history_unknown"];
    }
    private function fresh(
        AcademicCredit $root,
        array $facts,
        array $registration,
        int $version,
        int $position,
        int $milli,
    ): void {
        if (!$this->eligible($root, $registration)) {
            $this->invalid(
                "registered_milli",
                "Use an active attempt with verified original quantities and no pending grade correction. " .
                    "Closed or unknown earlier attempts require separate resolution.",
            );
        }
        if ($facts["version"] !== $version || $root->playhead() !== $position) {
            throw new ConcurrentModificationException(
                "The registration or academic record changed. Reload and review again.",
            );
        }
        if (
            $milli < 0 ||
            $milli > 999999 ||
            $milli === $root->registeredCredits()["attempted_milli"]
        ) {
            $this->invalid(
                "registered_milli",
                "Change registered credits to a value from zero to 999.999, using at most three decimals.",
            );
        }
    }
    private function intent(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        int $version,
        int $position,
        int $before,
        int $after,
        string $reason,
        bool $acknowledged,
    ): string {
        return hash(
            "sha256",
            json_encode(
                [
                    $student->value,
                    $section->value,
                    $enrollment->value,
                    $version,
                    $position,
                    $before,
                    $after,
                    $reason,
                    $acknowledged,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
    private function reason(string $reason, bool $acknowledged): string
    {
        $reason = trim($reason);
        if (!$acknowledged) {
            $this->invalid(
                "acknowledged",
                "Confirm institutional registration evidence and the separate award/billing consequences.",
            );
        }
        if (
            $reason === "" ||
            mb_strlen($reason) > 1000 ||
            !mb_check_encoding($reason, "UTF-8") ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            $this->invalid(
                "reason",
                "Explain the institutional evidence in at most 1,000 characters.",
            );
        }
        return $reason;
    }
    private function lock(EntityId $student, EntityId $section, EntityId $actor): void
    {
        $this->records->lockStudent($student);
        $this->records->lockSection($section);
        $this->sameActor($actor);
    }
    private function authorize(): EntityId
    {
        $actor = $this->access->requirePermission(RegisteredCreditCorrection::PERMISSION);
        foreach (["sis:student:read", "sis:registration:manage"] as $permission) {
            if ($this->access->requirePermission($permission)->value !== $actor->value) {
                throw new AccessDeniedException();
            }
        }
        return $actor;
    }
    private function sameActor(EntityId $actor): void
    {
        if ($this->authorize()->value !== $actor->value) {
            throw new AccessDeniedException();
        }
    }
    private function budget(): HistoryReadBudget
    {
        return new HistoryReadBudget(maximumBytes: 4 * 1024 * 1024, maximumMilliseconds: 5000);
    }
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new AcademicCreditId($id->value), "academic_credit");
    }
    private function invalid(string $field, string $message): never
    {
        throw new ValidationException([["field" => $field, "message" => $message]]);
    }
}
