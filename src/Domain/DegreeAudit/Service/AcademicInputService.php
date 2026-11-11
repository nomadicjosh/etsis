<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\AcademicRecord\Service\InstitutionalCreditLedger;
use Domain\DegreeAudit\Repository\AcademicInputRepository;
use Domain\DegreeAudit\ValueObject\AcademicInputSet;
use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\DegreeAudit\ValueObject\AcademicWorkView;
use Domain\Registration\Service\EnrollmentLedger;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Transfer\Service\TransferCreditLedger;
use InvalidArgumentException;

/** Checks surviving routing sources under a student lock. It neither evaluates nor repairs records. */
final readonly class AcademicInputService
{
    public function __construct(
        private AcademicInputRepository $inputs,
        private InstitutionalCreditLedger $institutional,
        private TransferCreditLedger $transfers,
        private EnrollmentLedger $registrations,
        private StudentRecordRepository $students,
        private StudentRequirementsService $requirements,
        private UnitOfWork $transactions,
    ) {
    }

    /** The optional budget is an internal shared work allowance, never an HTTP parameter. */
    public function collect(
        EntityId $student,
        EntityId $assignment,
        ?HistoryReadBudget $budget = null,
    ): AcademicInputSet {
        $this->requirements->authorizeRead($student, $assignment);
        try {
            return $this->transactions->run(function () use (
                $student,
                $assignment,
                $budget,
            ): AcademicInputSet {
                $this->students->lock($student);
                $this->requirements->authorizeRead($student, $assignment);
                $result = $this->verify($student, $budget ?? new HistoryReadBudget());
                $this->requirements->authorizeRead($student, $assignment);
                return $result;
            });
        } catch (HistoryReadLimitException) {
            $status = AcademicInputStatus::Limited;
        } catch (CorruptHistoryException | RecordNotFoundException | InvalidArgumentException) {
            $status = AcademicInputStatus::Inconsistent;
        }
        // A failed nested history read rolls back its transaction; no partial snapshot escapes it.
        $this->requirements->authorizeRead($student, $assignment);
        return new AcademicInputSet($status);
    }

    private function verify(EntityId $student, HistoryReadBudget $budget): AcademicInputSet
    {
        $items = [];
        $references = [];
        $gaps = false;
        foreach ($this->inputs->inventory($student)->sources as $source) {
            if (!is_string($source["credit_id"])) {
                throw new CorruptHistoryException();
            }
            $credit = new EntityId($source["credit_id"]);
            $registration = null;
            if ($source["origin"] === "institutional") {
                $input = $this->institutional->evaluationFacts($credit, $student, $budget);
                $registration = $this->registrations->evaluationFacts(
                    new EntityId($source["id"]),
                    $student,
                    $budget,
                );
                $r = $registration["facts"];
                $c = $input["credit"];
                if (
                    $r["id"] !== $source["id"] ||
                    $r["term_id"] !== $c["term_id"] ||
                    $r["status"] !== $c["status"] ||
                    ($source["section_id"] !== null && $r["section_id"] !== $source["section_id"]) ||
                    $source["registration_seen"] !== $registration["history_recorded"] ||
                    $source["source_seen"] !== ($r["status"] !== "D")
                ) {
                    throw new CorruptHistoryException();
                }
            } else {
                $input = $this->transfers->auditFacts($credit, $student, $budget);
                if (!$source["source_seen"] || !$source["credit_seen"] || $source["registration_seen"]) {
                    throw new CorruptHistoryException();
                }
            }
            $facts = $input["credit"];
            if (
                $facts["id"] !== $credit->value ||
                $facts["student_id"] !== $student->value ||
                $facts["credit_type"] !== $source["origin"] ||
                $facts["source_id"] !== $source["id"] ||
                ($source["term_id"] !== null && $facts["term_id"] !== $source["term_id"]) ||
                $source["academic_seen"] !== $input["history_recorded"] ||
                $source["credit_seen"] !== ($facts["status"] !== "D")
            ) {
                throw new CorruptHistoryException();
            }
            $removed = $facts["status"] === "D";
            $references[] = [
                "origin" => $source["origin"],
                "source_id" => $source["id"],
                "credit_id" => $credit->value,
                "academic_version" => $input["history_version"],
                "registration_version" => $registration["history_version"] ?? null,
                "removed" => $removed,
            ];
            if (!$removed) {
                $gaps =
                $gaps ||
                $input["earlier_history_unknown"] ||
                ($registration["earlier_history_unknown"] ?? false);
                $items[] = AcademicWorkView::fromLedger($credit->value, $input);
            }
        }
        return new AcademicInputSet(
            $gaps ? AcademicInputStatus::HistoryGaps : AcademicInputStatus::Verified,
            $items,
            $references,
        );
    }
}
