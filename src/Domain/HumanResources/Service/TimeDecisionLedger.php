<?php

declare(strict_types=1);

namespace Domain\HumanResources\Service;

use Domain\HumanResources\Event\TimeEntryWasDecided;
use Domain\HumanResources\TimeDecision;
use Domain\HumanResources\TimeEntry;
use Domain\HumanResources\ValueObject\TimeApprovalRules;
use Domain\HumanResources\ValueObject\TimeDecisionId;
use Domain\HumanResources\ValueObject\TimeReviewEvidence;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;

final readonly class TimeDecisionLedger
{
    public function __construct(private HistoricalEventStore $history, private Clock $clock)
    {
    }
    /** Selected private decision only; caller authorizes before/after and curates owner responses. */
    public function inspect(TimeEntry $entry): ?array
    {
        $root = TimeDecision::root(new TimeDecisionId($entry->id->value));
        $page = new HistoryReadBudget(
            maximumEvents: 2,
            maximumBytes: 131072,
            maximumMilliseconds: 5000,
        )->page($this->history, $this->stream($entry));
        if ($page->throughVersion > 1 || $page->nextCursor !== null) {
            throw new CorruptHistoryException();
        }
        foreach ($page->events as $stored) {
            $root->accept($stored);
        }
        if ($root->playhead() !== $page->throughVersion) {
            throw new CorruptHistoryException();
        }
        $p = $root->decision();
        if ($p === null) {
            return null;
        }
        $target = $p["evidence"]["target"];
        if (
            $p["employee_id"] !== $entry->employeeId->value ||
            $p["position_id"] !== $entry->positionId?->value ||
            $p["position_version"] !== $entry->positionVersion ||
            $p["next_status"] !== $entry->status ||
            $p["actor_id"] !== $entry->approvedBy?->value ||
            $target["version"] + 1 !== $entry->version ||
            $target["starts_at"] !== TimeReviewEvidence::facts($entry)["starts_at"] ||
            $target["ends_at"] !== TimeReviewEvidence::facts($entry)["ends_at"]
        ) {
            throw new CorruptHistoryException();
        }
        return $p;
    }
    public function record(
        TimeEntry $before,
        TimeEntry $after,
        #[\SensitiveParameter] TimeReviewEvidence $evidence,
        EntityId $actor,
        #[\SensitiveParameter] ?string $reason,
    ): void {
        if (
            $this->inspect($before) !== null ||
            $before->status !== "pending" ||
            $after->approvedBy?->value !== $actor->value ||
            $after->id->value !== $before->id->value ||
            $after->version !== $before->version + 1
        ) {
            throw new CorruptHistoryException();
        }
        $intent = TimeApprovalRules::hash([$evidence->data, $after->status, $reason]);
        $operation = DerivedIdentity::from(
            $before->id,
            "timesheet.decision:" . $actor->value . ":" . $intent,
        );
        $data = [
            "operation_id" => $operation->value,
            "entry_id" => $before->id->value,
            "employee_id" => $before->employeeId->value,
            "position_id" => $before->positionId?->value,
            "position_version" => $before->positionVersion,
            "evidence" => $evidence->data,
            "next_status" => $after->status,
            "exception_reason" => $reason,
            "intent" => $intent,
        ];
        $event = TimeEntryWasDecided::occur(new TimeDecisionId($before->id->value), $data, [
            "__aggregate_type" => "timesheet_decision",
        ])->withPlayhead(1);
        $this->history->append(
            $this->stream($before),
            $operation,
            0,
            new EventContext(
                $actor,
                $this->clock->now(),
                $operation,
                reason: $reason ?? "Reviewed time-entry decision.",
            ),
            [$event],
        );
    }
    private function stream(TimeEntry $entry): StreamReference
    {
        return new StreamReference(new TimeDecisionId($entry->id->value), "timesheet_decision");
    }
}
