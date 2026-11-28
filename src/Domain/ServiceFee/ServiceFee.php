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
    private array $captureStates = [];

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
            if ($p['action'] === 'capture_reserve') {
                $this->captureStates = \Domain\Payments\ValueObject\CaptureState::reserve(
                    $this->captureStates,
                    $d,
                    $this->due(),
                    $this->assessment['policy']['currency']
                );
            } elseif ($p['action'] === 'capture_progress') {
                $this->captureStates = \Domain\Payments\ValueObject\CaptureState::advance($this->captureStates, $d);
            } elseif ($p["action"] === "payment") {
                if (($this->assessment['accounting'] ?? null) !== null && !isset($d['asset'])) {
                    throw new CorruptHistoryException();
                }
                if (
                    isset($this->payments[$d["payment_id"]]) ||
                    $d["currency"] !==
                        $this->assessment["policy"]["currency"] ||
                    array_sum(array_column($this->payments, "minor")) >
                        2000000000 - $d["minor"]
                ) {
                    throw new CorruptHistoryException();
                }
                if (isset($this->captureStates[$d['payment_id']]) && $d['channel'] !== 'provider') {
                    throw new CorruptHistoryException();
                }
                if (isset($this->captureStates[$d['payment_id']]) && $d['channel'] === 'provider') {
                    $capture = $this->captureStates[$d['payment_id']];
                    if (
                        $capture['state'] !== 'received' || $d['minor'] !== $capture['received_minor']
                        || $d['session'] !== $capture['plan']['session']
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $this->captureStates = \Domain\Payments\ValueObject\CaptureState::advance(
                        $this->captureStates,
                        ['payment_id' => $d['payment_id'], 'state' => 'paid', 'received_minor' => $d['minor']]
                    );
                }
                if (($this->assessment['accounting'] ?? null) !== null) {
                    $asset = $d['channel'] === 'provider'
                    ? $this->captureStates[$d['payment_id']]['plan']['receipt']['asset']
                    : $this->assessment['accounting']['definition']['cash'];
                    if ($d['asset'] !== array_intersect_key($asset, array_flip(['id', 'code', 'title']))) {
                        throw new CorruptHistoryException();
                    }
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
        $this->assertCaptureBudget();
        $this->previous = $p["operation_id"];
    }
    public function captures(): array
    {
        return $this->captureStates;
    }

    public function due(): int
    {
        return $this->assessment === null || $this->waived
        ? 0 : max(0, $this->assessment['policy']['minor'] - $this->net());
    }

    public function assertCaptureBudget(): void
    {
        \Domain\Payments\ValueObject\CaptureState::assertBudget($this->captureStates, $this->due());
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
        ] + ($this->captureStates === [] ? [] : ['captures' => $this->captureStates]);
    }
    public function receipt(string $operation): ?array
    {
        return $this->receipts[$operation] ?? null;
    }
}
