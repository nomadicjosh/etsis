<?php

declare(strict_types=1);

namespace Domain\ServiceFee;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\ServiceFee\Event\ServiceFeeDecisionWasRecorded;
use Domain\ServiceFee\ValueObject\FeeSchema;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;

/** A prospect/student service charge and its actual receipts; never a student-account substitute. */
final class ServiceFee extends EventSourcedAggregate
{
    public const array FIELDS = FeeSchema::FIELDS;
    private ?array $assessment = null;
    private ?string $previous = null;
    private array $payments = [];
    private array $refunds = [];
    private bool $waived = false;
    private ?string $applied = null;
    private ?array $appliedReference = null;
    private array $receipts = [];

    public static function validate(array $p): void
    {
        FeeSchema::validate($p);
    }
    public function accept(StoredEvent $stored): void
    {
        $e = $stored->event;
        self::validate($p = $e->payload());
        if (
            !($e instanceof ServiceFeeDecisionWasRecorded) ||
            !$e->aggregateId()->equals($this->aggregateId()) ||
            $e->playhead() !== $this->playhead() + 1 ||
            $stored->context->origin !== "command" ||
            $stored->context->actor === null ||
            $stored->context->reason === null ||
            $stored->context->effectiveAt === null ||
            $stored->context->correlation?->value !== $p["operation_id"] ||
            $stored->context->causation?->value !== $this->previous ||
            $p["previous_id"] !== $this->previous ||
            isset($this->receipts[$p["operation_id"]]) ||
            $this->playhead() >= 200
        ) {
            throw new CorruptHistoryException();
        }
        $intent = FeeSchema::hash([
            (string) $this->aggregateId(),
            $p["action"],
            $p["details"],
            trim($stored->context->reason),
        ]);
        if (!hash_equals($p["intent"], $intent)) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($e);
        $this->playhead = $e->playhead();
        $this->receipts[$p["operation_id"]] = [
            "intent" => $intent,
            "actor" => $stored->context->actor->value,
        ];
    }
    public function whenServiceFeeDecisionWasRecorded(
        ServiceFeeDecisionWasRecorded $e,
    ): void {
        $p = $e->payload();
        $d = $p["details"];
        if ($p["action"] === "assess") {
            if ($this->assessment !== null) {
                throw new CorruptHistoryException();
            }
            $this->assessment = $d;
        } else {
            if ($this->assessment === null) {
                throw new CorruptHistoryException();
            }
            if ($p["action"] === "payment") {
                if (
                    isset($this->payments[$d["payment_id"]]) ||
                    $d["currency"] !==
                        $this->assessment["policy"]["currency"] ||
                    array_sum(array_column($this->payments, "minor")) >
                        2000000000 - $d["minor"]
                ) {
                    throw new CorruptHistoryException();
                }
                $this->payments[$d["payment_id"]] = $d;
            } elseif ($p["action"] === "waive") {
                if (
                    !$this->assessment["policy"]["waivers"] ||
                    $this->cleared() ||
                    $this->applied !== null
                ) {
                    throw new CorruptHistoryException();
                }
                $this->waived = true;
            } elseif ($p["action"] === "apply") {
                if (!$this->cleared() || $this->applied !== null) {
                    throw new CorruptHistoryException();
                }
                $this->applied = $d["target_id"];
                $this->appliedReference = [
                    "fee_id" => (string) $this->aggregateId(),
                    "decision_id" => $p["operation_id"],
                    "minor" => $this->assessment["policy"]["minor"],
                    "currency" => $this->assessment["policy"]["currency"],
                    "waived" => $this->waived,
                    "version" => $e->playhead(),
                ];
            } else {
                $payment =
                $this->payments[$d["payment_id"]] ??
                throw new CorruptHistoryException();
                $already = 0;
                foreach ($this->refunds as $refund) {
                    if ($refund["payment_id"] === $d["payment_id"]) {
                        $already += $refund["minor"];
                    }
                }
                if ($already > $payment["minor"] - $d["minor"]) {
                    throw new CorruptHistoryException();
                }
                $this->refunds[$p["operation_id"]] = $d;
            }
        }
        $this->previous = $p["operation_id"];
    }
    public function cleared(): bool
    {
        return $this->assessment !== null &&
        ($this->waived ||
        $this->net() >= $this->assessment["policy"]["minor"]);
    }
    public function net(): int
    {
        return array_sum(array_column($this->payments, "minor")) -
        array_sum(array_column($this->refunds, "minor"));
    }
    public function state(): array
    {
        return [
            "assessment" => $this->assessment,
            "decision_id" => $this->previous,
            "payments" => $this->payments,
            "refunds" => $this->refunds,
            "waived" => $this->waived,
            "applied_to" => $this->applied,
            "applied_reference" => $this->appliedReference,
            "cleared" => $this->cleared(),
            "net_minor" => $this->net(),
            "version" => $this->playhead(),
        ];
    }
    public function receipt(string $operation): ?array
    {
        return $this->receipts[$operation] ?? null;
    }
}
