<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** One protected academic source; no grades, quantities, removed-source IDs or event payloads. */
final readonly class AcademicRecordProjectionCheck
{
    public function __construct(
        public string $status,
        public EntityId $credit,
        public int $events,
        public ?int $currentRecords = null,
        public ?bool $earlierHistoryUnknown = null,
        public array $issues = [],
    ) {
        $complete = in_array($status, ["verified", "history_gaps"], true);
        if (
            !in_array(
                $status,
                [
                    "verified",
                    "history_gaps",
                    "unverified",
                    "changed",
                    "limited",
                ],
                true,
            ) ||
            $events < 0 ||
            $events > 2000 ||
            !array_is_list($issues) ||
            array_diff($issues, [
                "student_identity",
                "academic_sources",
                "unavailable",
            ]) !== [] ||
            ($complete &&
                (!in_array($currentRecords, [0, 1], true) ||
                    $earlierHistoryUnknown === null)) ||
            (!$complete &&
                ($currentRecords !== null ||
                    $earlierHistoryUnknown !== null)) ||
            ($status === "verified" &&
                ($earlierHistoryUnknown || $events === 0)) ||
            ($status === "history_gaps" && !$earlierHistoryUnknown) ||
            ($status === "unverified" ? $issues === [] : $issues !== [])
        ) {
            throw new InvalidArgumentException(
                "Use a bounded, consistent selected academic check.",
            );
        }
    }

    public function describe(): array
    {
        return [
            "status" => $this->status,
            "scope" => "academic_credit",
            "credit_reference" => $this->credit->value,
            "events_checked" => $this->events,
            "records_checked" => $this->currentRecords === null ? null : 1,
            "current_records" => $this->currentRecords,
            "earlier_history_unknown" => $this->earlierHistoryUnknown,
            "issues" => $this->issues,
            "records_changed" => false,
        ];
    }
}
