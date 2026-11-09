<?php

declare(strict_types=1);

namespace Domain\Reporting\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

final readonly class ReportRequestChange
{
    public string $note;
    public function __construct(
        public string $action,
        string $note,
        public ?EntityId $report = null,
        public ?string $deliveryRevision = null,
    ) {
        if (!in_array($action, ["claim", "clarify", "reply", "deliver", "cancel", "reopen", "note"], true)) {
            throw new ValidationException([["field" => "action", "message" => "Choose an available request action."]]);
        }
        $this->note =
        $action === "claim" && trim($note) === "" && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note) === 0
        ? ""
        : ReportRequestBrief::text("note", $note, 5000);
        if (
            $action === "deliver"
                ? $report === null ||
                    $deliveryRevision === null ||
                    preg_match('/^[a-f0-9]{64}$/D', $deliveryRevision) !== 1
                : $report !== null || $deliveryRevision !== null
        ) {
            throw new ValidationException([
                ["field" => "report_id", "message" => "Review the selected report before sharing it."],
            ]);
        }
    }
    /** @return array{action: string, note: string, report_id: ?string, delivery_revision: ?string} */
    public function values(): array
    {
        return [
            "action" => $this->action,
            "note" => $this->note,
            "report_id" => $this->report?->value,
            "delivery_revision" => $this->deliveryRevision,
        ];
    }
}
