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
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Service\TransferCreditLedger;

/** Internal cross-source verifier. The caller must authorize and hold the student lock.
 * Trusted operator checks may instead compare bounded routing/head boundaries before and after this read,
 * outside a transaction, and discard the result on change. Such observations authorize no academic decision.
 * No reads repair projections or create historical decisions. Never expose this port directly over HTTP.
 */
final readonly class AcademicInputVerifier
{
    public function __construct(
        private AcademicInputRepository $inputs,
        private InstitutionalCreditLedger $institutional,
        private TransferCreditLedger $transfers,
        private EnrollmentLedger $registrations,
    ) {
    }

    public function verify(EntityId $student, HistoryReadBudget $budget): AcademicInputSet
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
                    $r["term_id"] !== ($input["registration_term_id"] ?? $c["term_id"]) ||
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
                ($source["posting_term_id"] !== null && $facts["term_id"] !== $source["posting_term_id"]) ||
                ($source["term_id"] !== null &&
                    ($input["registration_term_id"] ?? $facts["term_id"]) !== $source["term_id"]) ||
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
                "section_id" => $registration["facts"]["section_id"] ?? null,
                "removed" => $removed,
                "earlier_history_unknown" =>
                    $input["earlier_history_unknown"] || ($registration["earlier_history_unknown"] ?? false),
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
