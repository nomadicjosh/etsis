<?php

declare(strict_types=1);

namespace Domain\HumanResources\Service;

use Domain\HumanResources\Event\PrimaryPositionWasRecorded;
use Domain\HumanResources\PrimaryPositionHistory;
use Domain\HumanResources\Repository\PrimaryPositionRepository;
use Domain\HumanResources\ValueObject\PrimaryPosition;
use Domain\HumanResources\ValueObject\PrimaryPositionId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Internal companion. Callers own employee authorization, transaction and parent locks. */
final readonly class PrimaryPositionLedger
{
    public function __construct(
        private PrimaryPositionRepository $rows,
        private HistoricalEventStore $history,
        private PositionLedger $positions,
        private Clock $clock,
    ) {
    }

    /** @return array{root:PrimaryPositionHistory,receipt:bool,items:list<array<string,mixed>>} */
    public function inspect(
        EntityId $employee,
        ?EntityId $operation = null,
        ?EntityId $actor = null,
        ?string $intent = null,
        ?HistoryReadBudget $budget = null,
    ): array {
        $root = PrimaryPositionHistory::root(new PrimaryPositionId($employee->value));
        $cursor = null;
        $head = null;
        $count = 0;
        $receipt = false;
        $items = [];
        do {
            $stream = new StreamReference($root->aggregateId(), 'employment_primary_position');
            $page = $budget === null
            ? $this->history->page($stream, $cursor, 100)
            : $budget->page($this->history, $stream, $cursor);
            if ($page->throughVersion > 2000) {
                throw new HistoryReadLimitException();
            }
            $head ??= $page->throughVersion;
            if (
                $head !== $page->throughVersion ||
                ($page->nextCursor !== null &&
                    ($page->events === [] || $page->nextCursor === $cursor))
            ) {
                throw new CorruptHistoryException();
            }
            foreach ($page->events as $stored) {
                if (++$count > 2000) {
                    throw new HistoryReadLimitException();
                }
                $root->accept($stored);
                $payload = $stored->event->payload();
                if ($payload["operation_id"] === $operation?->value) {
                    if (
                        $stored->context->actor?->value !== $actor?->value ||
                        $payload["intent"] !== $intent
                    ) {
                        throw new AccessDeniedException(
                            "This primary-position operation belongs to another review.",
                        );
                    }
                    $receipt = true;
                }
                $items[] = [
                    "version" => $root->playhead(),
                    "selection" => $root->designation()->selection,
                    "actor_id" => $stored->context->actor->value,
                    "reason" => $stored->context->reason,
                    "effective_at" => $stored->context->effectiveAt->format(DATE_ATOM),
                    "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                ];
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $row = $this->rows->find($employee);
        if (
            $root->playhead() !== $head ||
            ($row === null) !== ($head === 0) ||
            ($row !== null && $row->facts() !== $root->designation()->facts())
        ) {
            throw new CorruptHistoryException();
        }
        return ["root" => $root, "receipt" => $receipt, "items" => $items];
    }

    /** Current, minimized context; never grants authority or selects the position actually worked.
     * @return array<string,mixed>
     */
    public function summary(PrimaryPosition $designation, ?HistoryReadBudget $budget = null): array
    {
        return $this->summaryOn($designation, $budget, $this->clock->now()->format('Y-m-d'));
    }

    private function summaryOn(PrimaryPosition $designation, ?HistoryReadBudget $budget, string $today): array
    {
        $selection = $designation->selection;
        $status = $designation->version === 0 ? "unrecorded" : "cleared";
        if ($selection !== null) {
            $current = $this->positions->verified(
                $designation->employee,
                new EntityId($selection["id"]),
                $budget,
            );
            if (
                $selection["version"] > $current->version ||
                $selection["job_id"] !== $current->job["id"] ||
                $selection["code"] !== $current->job["code"] ||
                $selection["title"] !== $current->job["title"] ||
                $selection["from"] !== $current->from
            ) {
                throw new CorruptHistoryException();
            }
            if ($selection['version'] === $current->version && $selection !== PrimaryPosition::selection($current)) {
                throw new CorruptHistoryException();
            }
            $status = $current->voided
            ? "voided"
            : ($current->from > $today
                    ? "scheduled"
                    : ($current->through !== null && $current->through < $today
                        ? "ended"
                        : ($current->version !== $selection["version"]
                            ? "changed"
                            : "current")));
        }
        return ["selection" => $selection, "version" => $designation->version, "status" => $status];
    }

    /** Internal approval observation; callers authorize and lock the employee. No pay or HR reasons. */
    public function approvalEvidence(EntityId $employee, HistoryReadBudget $budget): array
    {
        $checkedOn = $this->clock->now()->format('Y-m-d');
        $designation = $this->inspect($employee, budget: $budget)['root']->designation();
        $summary = $this->summaryOn($designation, $budget, $checkedOn);
        return [
            'designation_version' => $designation->version,
            'status' => $summary['status'],
            'position_id' => $designation->selection['id'] ?? null,
            'position_version' => $designation->selection['version'] ?? null,
            'checked_on' => $checkedOn,
        ];
    }

    public function record(
        PrimaryPositionHistory $root,
        PrimaryPosition $next,
        EntityId $operation,
        EntityId $actor,
        string $intent,
        #[\SensitiveParameter] string $reason,
    ): void {
        if ($root->playhead() >= 2000) {
            throw new HistoryReadLimitException();
        }
        $version = $root->playhead();
        $event = PrimaryPositionWasRecorded::occur(
            $root->aggregateId(),
            [
                "operation_id" => $operation->value,
                "previous_operation_id" => $root->operation(),
                "facts" => $next->facts(),
                "intent" => $intent,
            ],
            ["__aggregate_type" => "employment_primary_position"],
        )->withPlayhead($version + 1);
        $root->applyRecord($event);
        $this->history->append(
            new StreamReference($root->aggregateId(), "employment_primary_position"),
            $operation,
            $version,
            new EventContext($actor, $this->clock->now(), $operation, reason: $reason),
            [$event],
        );
        $this->rows->save($next, $version);
    }
}
