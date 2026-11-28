<?php

declare(strict_types=1);

namespace Domain\Tuition\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Service\LedgerControl;
use Domain\Finance\ValueObject\LedgerSchema;
use Domain\Finance\ValueObject\Money;
use Domain\Finance\ValueObject\TermFeeQuote;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Tuition\TuitionBill;

/** Registration's internal invoice-policy branch. No money is posted until the separate invoice decision. */
final readonly class TuitionBilling
{
    public function __construct(
        private TuitionLedger $ledger,
        private LedgerControl $control,
        private AdmissionsRepository $admissions,
        private RegistrationRepository $registrations,
        private AuditTrail $audit,
    ) {
    }

    public function assigned(EntityId $student, EntityId $term): ?array
    {
        $program = $this->admissions->student($student)->programId;
        foreach (
            ["student" => $student, "program" => $program, "term" => $term] as $scope => $id
        ) {
            $assignment = $this->ledger->setting(
                "assignments",
                TuitionLedger::assignmentId($scope, $id),
            );
            if (($assignment["definition"]["schedule_id"] ?? null) !== null) {
                $schedule =
                $this->ledger->setting(
                    "schedules",
                    new EntityId($assignment["definition"]["schedule_id"]),
                ) ??
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
                return ["assignment" => $assignment, "schedule" => $schedule];
            }
        }
        return null;
    }

    /** Mutation guard: serialize assignment activation with immediate fee posting. Caller owns the transaction. */
    public function requiresInvoice(EntityId $student, EntityId $term): bool
    {
        $this->control->records->lock();
        return $this->ledger->records->find(
            "bills",
            TuitionLedger::billId($student, $term),
        ) !== null || $this->assigned($student, $term) !== null;
    }

    public function snapshot(
        EntityId $student,
        EntityId $term,
        TermFeeQuote $legacy,
    ): ?array {
        $selected = $this->assigned($student, $term);
        if ($selected === null) {
            return null;
        }
        $schedule = $selected["schedule"];
        $currency = $schedule["definition"]["currency"];
        $book =
        $this->control->book($currency) ??
        throw new \DomainException("Configure accounting before tuition.");
        if (
            $legacy->account !== null &&
            $legacy->account->balance()->currency !== $currency
        ) {
            throw new \DomainException(
                "The tuition currency must match the existing student term account.",
            );
        }
        $rules = [];
        foreach ($legacy->rules as $rule) {
            if (
                $rule->kind() !== "fee" ||
                $rule->amount()->currency !== $currency
            ) {
                throw new \DomainException(
                    "Resolve earlier tuition rules and mixed-currency fees before invoicing.",
                );
            }
            if (
                $legacy->account?->entry(
                    new EntityId((string) $rule->aggregateId()),
                ) !== null
            ) {
                continue;
            }
            $rules[] = [
                "id" => (string) $rule->aggregateId(),
                "name" => $rule->name(),
                "minor" => $rule->amount()->minor,
                "basis" => $rule->basis()->value,
            ];
        }
        $d = $book["definition"];
        return [
            "schedule_id" => $schedule["id"],
            "schedule_event_id" => $schedule["event_id"],
            "assignment_event_id" => $selected["assignment"]["event_id"],
            "definition" => $schedule["definition"],
            "book_event_id" => $book["event_id"],
            "rules" => $rules,
            "fee_accounts" => [
                "section" =>
                    $d["charge_sources"]["section_fee"] ?? $d["charge"],
                "term" => $d["charge_sources"]["term_fee"] ?? $d["charge"],
                "credit" => $d["charge_sources"]["credit_fee"] ?? $d["charge"],
            ],
        ];
    }

    public function preview(
        EntityId $student,
        EntityId $term,
        Money $fee,
        int $credits,
        TermFeeQuote $legacy,
        HistoryReadBudget $budget,
    ): ?array {
        $id = TuitionLedger::billId($student, $term);
        $root = $this->ledger->bill($id, $budget);
        $state = $root->state();
        $snapshot =
        $state["snapshot"] ?? $this->snapshot($student, $term, $legacy);
        if ($snapshot === null) {
            return null;
        }
        if ($fee->currency !== $snapshot["definition"]["currency"]) {
            throw new \DomainException(
                "Section fees and tuition must use one currency.",
            );
        }
        $course = [
            "enrollment_id" => "00000000000000000000000000",
            "minor" => $fee->minor,
            "credits_milli" => $credits,
        ];
        $estimated = TuitionBill::estimate($snapshot, $state, $course);
        return [
            "bill_id" => $id->value,
            "version" => $root->playhead(),
            "snapshot" => $snapshot,
            "state" => $state,
            "before_minor" => $root->pending()["total_minor"],
            "after" => $estimated["pending"],
        ];
    }

    public function accept(
        EntityId $student,
        EntityId $term,
        EntityId $enrollment,
        Money $fee,
        int $credits,
        TermFeeQuote $legacy,
        EventContext $cause,
        ?array $held,
    ): bool {
        $this->control->records->lock();
        $fresh = $this->preview(
            $student,
            $term,
            $fee,
            $credits,
            $legacy,
            new HistoryReadBudget(),
        );
        if (
            ($fresh === null) !== ($held === null) ||
            ($fresh !== null &&
                LedgerSchema::hash($fresh["snapshot"]) !==
                    LedgerSchema::hash($held["snapshot"]))
        ) {
            throw new ConcurrentModificationException(
                "The tuition assignment or published price changed. Review again.",
            );
        }
        if ($fresh === null) {
            return false;
        }
        $id = new EntityId($fresh["bill_id"]);
        $root = $this->ledger->bill($id);
        if ($root->playhead() === 0) {
            if (
                $this->ledger->records->earlierRegistration(
                    $student,
                    $term,
                    $enrollment,
                )
            ) {
                throw new \DomainException(
                    "Earlier term registrations need explicit opening billing review.",
                );
            }
            $operation = self::operation($enrollment, "start");
            $root = $this->ledger->record(
                $id,
                $operation,
                "start",
                [
                    "student_id" => $student->value,
                    "term_id" => $term->value,
                    "snapshot" => $fresh["snapshot"],
                ],
                0,
                self::context($cause, $operation),
            );
        }
        $operation = self::operation($enrollment, "course");
        $this->ledger->record(
            $id,
            $operation,
            "course",
            [
                "enrollment_id" => $enrollment->value,
                "minor" => $fee->minor,
                "credits_milli" => $credits,
                "section_label" => $this->ledger->records->sectionLabel(
                    $enrollment,
                ),
            ],
            $root->playhead(),
            self::context($cause, $operation),
        );
        $this->audit->record(
            $cause->actor,
            "tuition.pending_registration",
            $id,
        );
        return true;
    }

    public function remove(
        EntityId $student,
        EntityId $term,
        EntityId $enrollment,
        EventContext $cause,
    ): void {
        $this->control->records->lock();
        $id = TuitionLedger::billId($student, $term);
        $root = $this->ledger->bill($id);
        if (!isset($root->state()["courses"][$enrollment->value])) {
            return;
        }
        $operation = self::operation(
            $cause->correlation,
            "remove:" . $enrollment->value,
        );
        $this->ledger->record(
            $id,
            $operation,
            "remove",
            ["enrollment_id" => $enrollment->value],
            $root->playhead(),
            self::context($cause, $operation),
        );
        $this->audit->record($cause->actor, "tuition.pending_drop", $id);
    }

    private static function operation(EntityId $source, string $phase): EntityId
    {
        return \Domain\Gradebook\ValueObject\PublishedGradeId::forVersion(
            $source,
            "tuition:" . $phase,
        );
    }

    private static function context(
        EventContext $cause,
        EntityId $operation,
    ): EventContext {
        return new EventContext(
            $cause->actor,
            $cause->effectiveAt,
            $operation,
            $cause->correlation,
            $cause->reason,
            origin: $cause->origin,
        );
    }
}
