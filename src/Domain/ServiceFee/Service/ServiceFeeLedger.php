<?php

declare(strict_types=1);

namespace Domain\ServiceFee\Service;

use Domain\ServiceFee\Event\ServiceFeeDecisionWasRecorded;
use Domain\ServiceFee\Repository\ServiceFeeRepository;
use Domain\ServiceFee\ServiceFee;
use Domain\ServiceFee\ValueObject\FeeSchema;
use Domain\ServiceFee\ValueObject\ServiceFeeId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;

/** Internal source port. Caller owns authorization, the person lock and the shared transaction. */
final readonly class ServiceFeeLedger
{
    public function __construct(
        private ServiceFeeRepository $records,
        private HistoricalEventStore $history,
        private \Domain\Finance\Repository\LedgerControlRepository $controls,
    ) {
    }
    public static function stream(EntityId $fee): StreamReference
    {
        return new StreamReference(
            new ServiceFeeId($fee->value),
            "service_fee",
        );
    }
    public function load(
        EntityId $fee,
        ?HistoryReadBudget $budget = null,
    ): ServiceFee {
        $budget ??= new HistoryReadBudget();
        $root = ServiceFee::root(new ServiceFeeId($fee->value));
        $cursor = null;
        $through = null;
        do {
            $page = $budget->page($this->history, self::stream($fee), $cursor);
            $through ??= $page->throughVersion;
            if ($through !== $page->throughVersion) {
                throw new CorruptHistoryException();
            }
            foreach ($page->events as $stored) {
                $root->accept($stored);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $row = $this->records->find($fee);
        $s = $root->state();
        if (
            $root->playhead() !== $through ||
            ($row === null) !== ($through === 0) ||
            ($row !== null &&
                ($row["source_hash"] !== FeeSchema::hash($s) ||
                    (int) $row["version"] !== $through ||
                    $row["person_id"] !== $s["assessment"]["person_id"] ||
                    $row["owner_user_id"] !== $s["assessment"]["owner_id"] ||
                    $row["purpose"] !== $s["assessment"]["purpose"] ||
                    $row["target_hash"] !==
                        FeeSchema::hash($s["assessment"]["target"]) ||
                    $row["applied_to"] !== $s["applied_to"]))
        ) {
            throw new CorruptHistoryException();
        }
        return $root;
    }
    public function decisions(EntityId $fee): array
    {
        $budget = new HistoryReadBudget();
        $this->load($fee, $budget);
        $cursor = null;
        $result = [];
        do {
            $page = $budget->page($this->history, self::stream($fee), $cursor);
            array_push($result, ...$page->events);
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return $result;
    }
    public function record(
        EntityId $fee,
        EntityId $operation,
        string $action,
        array $details,
        int $expected,
        EventContext $context,
        ?HistoryReadBudget $budget = null,
    ): ServiceFee {
        $this->controls->lock();
        $root = $this->load($fee, $budget);
        $intent = FeeSchema::hash([
            $fee->value,
            $action,
            $details,
            trim($context->reason ?? ""),
        ]);
        $old = $root->receipt($operation->value);
        if ($old !== null) {
            if (
                $old["intent"] !== $intent ||
                $old["actor"] !== $context->actor?->value
            ) {
                throw new ConcurrentModificationException(
                    "This fee operation has already been used.",
                );
            }
            return $root;
        }
        if ($root->playhead() !== $expected) {
            throw new ConcurrentModificationException(
                "This fee changed. Review it again.",
            );
        }
        $payload = [
            "operation_id" => $operation->value,
            "previous_id" => $root->state()["decision_id"],
            "action" => $action,
            "details" => $details,
            "intent" => $intent,
        ];
        $event = ServiceFeeDecisionWasRecorded::occur(
            new ServiceFeeId($fee->value),
            $payload,
            ["__aggregate_type" => "service_fee"],
        )->withPlayhead($expected + 1);
        $this->history->append(
            self::stream($fee),
            $operation,
            $expected,
            $context,
            [$event],
        );
        $stored =
        $this->history->latest(self::stream($fee)) ??
        throw new CorruptHistoryException();
        $root->accept($stored);
        $this->records->save($root, $expected);
        return $root;
    }
}
