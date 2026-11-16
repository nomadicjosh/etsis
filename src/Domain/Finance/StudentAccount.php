<?php

declare(strict_types=1);

namespace Domain\Finance;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\Metadata;
use DomainException;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Event\AccountEntryWasPosted;
use Domain\Finance\Event\AccountChargeWasCredited;
use Domain\Finance\Event\AccountPaymentDetailsWereChanged;
use Domain\Finance\ValueObject\PaymentDetails;
use Domain\Finance\ValueObject\RefundAllocation;
use Domain\Finance\Event\AccountEntryWasCorrected;
use Domain\Finance\Event\AccountEntryWasReversed;
use Domain\Finance\Event\StudentAccountWasOpened;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\ValidationException;

/**
 * @phpstan-type AccountEntry array{operation_id:string,type:string,minor:int,currency:string,reversed:bool,
 *     corrects_operation_id?:string,corrected_by_operation_id?:string,source_operation_id?:string,
 *     credited_minor?:int,remaining_charge_minor?:int,linked_credit_count?:int,reversed_with_operation_id?:string,refund_allocations?:list<array{payment_operation_id:string,amount_minor:int}>,
 *     linked_refund_minor?:int,linked_refund_count?:int,remaining_refundable_minor?:int}
 * @phpstan-type LinkedEntryResult array{status:string,operation_id:string,source_operation_id:string,
 *     balance_minor:int,position:int}
 * @phpstan-type ReversalResult array{status:string,operation_id:string,balance_minor:int,position:int}
 * @phpstan-type PostingResult array{status:string,operation_id:string,balance_minor:int,position:int}
 * @phpstan-type PaymentDetailsResult array{status:string,operation_id:string,entry_operation_id:string,
 *     balance_minor:int,position:int}
 */
final class StudentAccount extends EventSourcedAggregate
{
    private EntityId $studentId;
    private EntityId $termId;
    private Money $balance;
    /** @var array<string, array{type: string, minor: int, currency: string}> */
    private array $operations = [];
    /** @var array<string, true> */
    private array $reversedOperations = [];
    /** @var array<string, true> Explicitly staff-recorded sources; earlier unknown provenance stays unknown. */
    private array $staffRecorded = [];
    /** @var array<string, string> */
    private array $correctedBy = [];
    /** @var array<string, array{actor:string,hash:string,result:LinkedEntryResult}> */
    private array $corrections = [];
    /** @var array<string, array{actor:string,hash:string,result:PostingResult}> */
    private array $postingReceipts = [];

    /** @var array<string,string> Monetary replacements share one logical receipt. */
    private array $receiptRoots = [];
    /** @var array<string,PaymentDetails> Current details; absence means not recorded. */
    private array $paymentDetails = [];
    /** @var array<string,PaymentDetails> Opening snapshots keep raw posting retries stable after corrections. */
    private array $openingPaymentDetails = [];
    /** @var array<string,array{actor:string,hash:string,result:PaymentDetailsResult}> */
    private array $paymentDetailsReceipts = [];

    /** @var array<string,array{actor:string,hash:string,result:ReversalResult}> */
    private array $reversalReceipts = [];

    /** @var array<string,string> Credit entry to original charge. */
    private array $chargeCreditSources = [];
    /** @var array<string,list<string>> Indexed per charge; statement work stays linear. */
    private array $creditsByCharge = [];
    /** @var array<string,string> Credits reversed atomically with their parent charge. */
    private array $reversedWith = [];
    /** @var array<string,array{actor:string,hash:string,result:LinkedEntryResult}> */
    private array $chargeCreditReceipts = [];

    /** @var array<string,RefundAllocation> Explicit recorded funding; never inferred from balances. */
    private array $refundAllocations = [];
    /** @var array<string,array{minor:int,count:int}> Active linked totals by logical payment receipt. */
    private array $refundTotals = [];
    /** @var array<string,string> The currently posted payment in each original/replacement family. */
    private array $activePaymentByReceipt = [];

    public static function open(AccountId $id, EntityId $studentId, EntityId $termId, string $currency): self
    {
        $money = new Money(0, $currency);
        $account = self::root($id);
        $account->recordThat(
            StudentAccountWasOpened::occur(
                $id,
                [
                    "student_id" => $studentId->value,
                    "term_id" => $termId->value,
                    "currency" => $money->currency,
                ],
                [Metadata::AGGREGATE_TYPE => "student_account"],
            ),
        );

        return $account;
    }

    public function studentId(): EntityId
    {
        return $this->studentId;
    }

    public function termId(): EntityId
    {
        return $this->termId;
    }

    /** Positive is owed to the institution; negative is available credit. */
    public function balance(): Money
    {
        return $this->balance;
    }

    /** @return list<AccountEntry> */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->operations as $id => $entry) {
            $flags =
            ["reversed" => isset($this->reversedOperations[$id])] +
            $this->correctionLinks($id) +
            $this->chargeLinks($id) +
            $this->refundLinks($id);
            $entries[] = ["operation_id" => $id] + $entry + $flags;
        }

        return $entries;
    }

    /** @return AccountEntry|null */
    public function entry(EntityId $operation): ?array
    {
        $entry = $this->operations[$operation->value] ?? null;
        if ($entry === null) {
            return null;
        }
        return ["operation_id" => $operation->value] +
        $entry + ["reversed" => isset($this->reversedOperations[$operation->value])] +
        $this->correctionLinks($operation->value) +
        $this->chargeLinks($operation->value) +
        $this->refundLinks($operation->value);
    }

    public function post(
        EntityId $operationId,
        EntryType $type,
        Money $amount,
        EntityId $actorId,
        bool $staffRecorded = false,
        ?string $reviewHash = null,
        #[\SensitiveParameter] ?PaymentDetails $paymentDetails = null,
        ?RefundAllocation $allocation = null,
    ): void {
        if ($type === EntryType::ChargeCredit) {
            throw new DomainException("A charge credit requires its original charge and a reviewed instruction.");
        }
        if ($amount->minor <= 0 || $amount->currency !== $this->balance->currency) {
            throw new DomainException("A positive amount in the account currency is required.");
        }
        if ($allocation !== null && ($type !== EntryType::Refund || !$staffRecorded)) {
            throw new DomainException("A payment link belongs to a staff-recorded refund.");
        }
        $paymentDetails = $paymentDetails?->isEmpty() ? null : $paymentDetails;
        if ($paymentDetails !== null && (!$staffRecorded || $type === EntryType::Charge)) {
            throw new DomainException("Receipt details belong to staff-recorded payments or refunds.");
        }
        if (isset($this->paymentDetailsReceipts[$operationId->value])) {
            throw new DomainException("The operation identifier already belongs to a receipt correction.");
        }
        $entry = ["type" => $type->value, "minor" => $amount->minor, "currency" => $amount->currency];
        if (isset($this->operations[$operationId->value])) {
            if (
                $this->operations[$operationId->value] !== $entry ||
                ($this->openingPaymentDetails[$operationId->value] ?? null)?->values() !==
                    $paymentDetails?->values() ||
                ($this->refundAllocations[$operationId->value] ?? null)?->values() !== $allocation?->values()
            ) {
                throw new DomainException("The operation identifier has already been used.");
            }
            return;
        }
        if ($reviewHash !== null && (!$staffRecorded || preg_match('/^[a-f0-9]{64}$/D', $reviewHash) !== 1)) {
            throw new DomainException("A reviewed staff posting requires a valid instruction hash.");
        }
        $this->postingBalance($type, $amount, $allocation);
        $this->recordThat(
            AccountEntryWasPosted::occur(
                $this->aggregateId(),
                $entry + [
                    "operation_id" => $operationId->value,
                    "actor_id" => $actorId->value,
                ],
                [Metadata::AGGREGATE_TYPE => "student_account"] +
                    ($staffRecorded ? ["staff_recorded" => true] : []) +
                    ($reviewHash === null ? [] : ["review_hash" => $reviewHash]) +
                    ($paymentDetails === null ? [] : ["payment_details" => $paymentDetails->values()]) +
                    ($allocation === null ? [] : ["refund_allocations" => $allocation->values()]),
            ),
        );
    }

    public function postingBalance(EntryType $type, Money $amount, ?RefundAllocation $allocation = null): Money
    {
        if ($type === EntryType::ChargeCredit) {
            throw new DomainException("A charge credit requires its original charge and a reviewed instruction.");
        }
        if ($amount->minor <= 0 || $amount->currency !== $this->balance->currency) {
            throw new DomainException("A positive amount in the account currency is required.");
        }
        if ($type === EntryType::Refund && $amount->minor > -$this->balance->minor) {
            throw new DomainException("A refund cannot exceed available credit.");
        }
        if ($allocation !== null) {
            if ($type !== EntryType::Refund) {
                throw new DomainException("Payment allocations belong to a refund.");
            }
            $this->validateAllocation($allocation, $amount);
        }
        $delta = $type->balanceSign() * $amount->minor;
        return $this->balance->add(new Money($delta, $amount->currency));
    }

    /** @return PostingResult|null */
    public function postingResult(EntityId $operation, EntityId $actor, string $reviewHash): ?array
    {
        $receipt = $this->postingReceipts[$operation->value] ?? null;
        if ($receipt === null) {
            return null;
        }
        if ($receipt["actor"] !== $actor->value || !hash_equals($receipt["hash"], $reviewHash)) {
            throw new DomainException("This posting belongs to a different reviewed instruction.");
        }
        return $receipt["result"];
    }

    /** @return PostingResult */
    public function postReviewed(
        EntityId $operation,
        EntryType $type,
        Money $amount,
        EntityId $actor,
        string $reviewHash,
        #[\SensitiveParameter] ?PaymentDetails $paymentDetails = null,
        ?RefundAllocation $allocation = null,
    ): array {
        if (($accepted = $this->postingResult($operation, $actor, $reviewHash)) !== null) {
            return $accepted;
        }
        if ($this->entry($operation) !== null) {
            throw new DomainException("A reviewed posting cannot adopt an existing entry identity.");
        }
        $this->post(
            $operation,
            $type,
            $amount,
            $actor,
            staffRecorded: true,
            reviewHash: $reviewHash,
            paymentDetails: $paymentDetails,
            allocation: $allocation,
        );
        return $this->postingReceipts[$operation->value]["result"];
    }

    public function canRefundPayment(EntityId $payment): bool
    {
        return ($this->operations[$payment->value]["type"] ?? null) === EntryType::Payment->value &&
        !isset($this->reversedOperations[$payment->value]) &&
        $this->balance->minor < 0 &&
        $this->paymentRefundSummary($payment)["remaining_refundable_minor"] > 0;
    }

    /** Current totals follow the logical receipt across amount corrections, without changing recorded links.
     * @return array{linked_refund_minor:int,linked_refund_count:int,remaining_refundable_minor:int}
     */
    public function paymentRefundSummary(EntityId $payment): array
    {
        if (($this->operations[$payment->value]["type"] ?? null) !== EntryType::Payment->value) {
            throw new ValidationException([
                ["field" => "source_operation_id", "message" => "Choose a recorded payment."],
            ]);
        }
        $root = $this->receiptRoots[$payment->value];
        $totals = $this->refundTotals[$root] ?? ["minor" => 0, "count" => 0];
        $current = $this->activePaymentByReceipt[$root] ?? null;
        $received = $current === null ? 0 : $this->operations[$current]["minor"];
        return [
            "linked_refund_minor" => $totals["minor"],
            "linked_refund_count" => $totals["count"],
            "remaining_refundable_minor" =>
                $current !== $payment->value ? 0 : max(0, $received - $totals["minor"]),
        ];
    }

    public function unlinkedRefundMinor(): int
    {
        $minor = 0;
        foreach ($this->operations as $id => $entry) {
            if (
                $entry["type"] === EntryType::Refund->value &&
                !isset($this->reversedOperations[$id]) &&
                !isset($this->refundAllocations[$id])
            ) {
                $minor += $entry["minor"];
            }
        }
        return $minor;
    }

    /** @return array{refund_allocations?:list<array{payment_operation_id:string,amount_minor:int}>,linked_refund_minor?:int,
     *     linked_refund_count?:int,remaining_refundable_minor?:int} */
    private function refundLinks(string $id): array
    {
        if ($this->operations[$id]["type"] === EntryType::Payment->value) {
            return $this->paymentRefundSummary(new EntityId($id));
        }
        return isset($this->refundAllocations[$id])
        ? ["refund_allocations" => $this->refundAllocations[$id]->values()]
        : [];
    }

    /** Derive only from accepted refund facts. Caller controls the corresponding active/reversed state. */
    private function changeRefundTotals(string $refund, int $sign): void
    {
        foreach (($this->refundAllocations[$refund] ?? null)?->values() ?? [] as $payment) {
            $root = $this->receiptRoots[$payment["payment_operation_id"]];
            $totals = $this->refundTotals[$root] ?? ["minor" => 0, "count" => 0];
            $this->refundTotals[$root] = [
                "minor" => $totals["minor"] + $sign * $payment["amount_minor"],
                "count" => $totals["count"] + $sign,
            ];
        }
    }

    /** Existing shares may be retained or reduced even after a payment is corrected or reversed. */
    private function validateAllocation(
        RefundAllocation $allocation,
        Money $amount,
        ?EntityId $original = null,
    ): void {
        if ($allocation->totalMinor() !== $amount->minor) {
            throw new ValidationException([
                [
                    "field" => "refund_allocations",
                    "message" => "Payment allocations must equal the full refund amount.",
                ],
            ]);
        }
        $previous = [];
        foreach (($this->refundAllocations[$original->value ?? ""] ?? null)?->values() ?? [] as $payment) {
            $previous[$this->receiptRoots[$payment["payment_operation_id"]]] = $payment["amount_minor"];
        }
        $seen = [];
        foreach ($allocation->values() as $payment) {
            $id = $payment["payment_operation_id"];
            $entry = $this->operations[$id] ?? null;
            if ($entry === null || $entry["type"] !== EntryType::Payment->value) {
                throw new ValidationException([
                    [
                        "field" => "refund_allocations",
                        "message" => "Choose recorded payments from this account.",
                    ],
                ]);
            }
            $root = $this->receiptRoots[$id];
            if (isset($seen[$root])) {
                throw new ValidationException([
                    [
                        "field" => "refund_allocations",
                        "message" => "Use each payment receipt only once, including its corrections.",
                    ],
                ]);
            }
            $seen[$root] = true;
            $oldMinor = $previous[$root] ?? 0;
            $current = $this->activePaymentByReceipt[$root] ?? null;
            $received = $current === null ? 0 : $this->operations[$current]["minor"];
            $other = ($this->refundTotals[$root]["minor"] ?? 0) - $oldMinor;
            if (
                $payment["amount_minor"] > $oldMinor &&
                ($current === null ||
                    ($oldMinor === 0 && $current !== $id) ||
                    $payment["amount_minor"] > max(0, $received - $other))
            ) {
                throw new ValidationException([
                    [
                        "field" => "refund_allocations",
                        "message" => "An allocation cannot exceed its posted payment less other linked refunds.",
                    ],
                ]);
            }
        }
    }

    /** @return ReversalResult|null */
    public function reversalResult(EntityId $operation, EntityId $actor, string $hash): ?array
    {
        $receipt = $this->reversalReceipts[$operation->value] ?? null;
        if ($receipt === null) {
            return null;
        }
        if ($receipt["actor"] !== $actor->value || !hash_equals($receipt["hash"], $hash)) {
            throw new DomainException("This reversal belongs to a different reviewed instruction.");
        }
        return $receipt["result"];
    }

    /** @return ReversalResult */
    public function reverseReviewed(EntityId $operation, EntityId $actor, string $hash): array
    {
        if (($accepted = $this->reversalResult($operation, $actor, $hash)) !== null) {
            return $accepted;
        }
        $this->reversalBalance($operation);
        $this->reverse($operation, $actor, $hash);
        return $this->reversalReceipts[$operation->value]["result"];
    }

    public function reversalBalance(EntityId $operation): Money
    {
        $entry = $this->entry($operation);
        if ($entry === null || $entry["reversed"]) {
            throw new ValidationException([
                [
                    "field" => "source_operation_id",
                    "message" => "Choose a currently posted entry. A reversed entry remains in history.",
                ],
            ]);
        }
        $delta =
        -EntryType::from($entry["type"])->balanceSign() * $entry["minor"] +
        ($entry["credited_minor"] ?? 0);
        $minor = $this->balance->minor + $delta;
        if ($minor < -Money::MAX_MINOR || $minor > Money::MAX_MINOR) {
            throw new ValidationException([
                [
                    "field" => "source_operation_id",
                    "message" => "The reversed balance exceeds the supported account limit.",
                ],
            ]);
        }
        return new Money($minor, $entry["currency"]);
    }

    public function reverse(EntityId $operationId, EntityId $actorId, ?string $reviewHash = null): void
    {
        if (isset($this->reversedOperations[$operationId->value])) {
            return;
        }
        if ($reviewHash !== null && preg_match('/^[a-f0-9]{64}$/D', $reviewHash) !== 1) {
            throw new DomainException("A reviewed reversal requires a valid instruction hash.");
        }
        $this->reversalBalance($operationId);
        $credits = $this->activeChargeCredits($operationId->value);
        $this->recordThat(
            AccountEntryWasReversed::occur(
                $this->aggregateId(),
                ["operation_id" => $operationId->value, "actor_id" => $actorId->value],
                [Metadata::AGGREGATE_TYPE => "student_account"] +
                    ($reviewHash === null ? [] : ["review_hash" => $reviewHash]) +
                    ($credits === [] ? [] : ["reversed_credit_ids" => $credits]),
            ),
        );
    }

    public function canCreditCharge(EntityId $source): bool
    {
        return ($this->operations[$source->value]["type"] ?? null) === EntryType::Charge->value &&
        !isset($this->reversedOperations[$source->value]) &&
        $this->chargeCreditSummary($source->value)["remaining_charge_minor"] > 0;
    }

    /** @return array{credited_minor:int,remaining_charge_minor:int,linked_credit_count:int} */
    private function chargeCreditSummary(string $source): array
    {
        $total = 0;
        $credits = $this->activeChargeCredits($source);
        foreach ($credits as $id) {
            $total += $this->operations[$id]["minor"];
        }
        return [
            "credited_minor" => $total,
            "remaining_charge_minor" => isset($this->reversedOperations[$source])
                ? 0
                : $this->operations[$source]["minor"] - $total,
            "linked_credit_count" => count($credits),
        ];
    }

    /** @return list<string> */
    private function activeChargeCredits(string $source): array
    {
        return array_values(
            array_filter(
                $this->creditsByCharge[$source] ?? [],
                fn(string $id): bool => !isset($this->reversedOperations[$id]),
            ),
        );
    }

    /** @return array{source_operation_id?:string,credited_minor?:int,remaining_charge_minor?:int,
     *     linked_credit_count?:int,reversed_with_operation_id?:string} */
    private function chargeLinks(string $id): array
    {
        $links = [];
        if (isset($this->chargeCreditSources[$id])) {
            $links["source_operation_id"] = $this->chargeCreditSources[$id];
        }
        if (isset($this->reversedWith[$id])) {
            $links["reversed_with_operation_id"] = $this->reversedWith[$id];
        }
        if ($this->operations[$id]["type"] === EntryType::Charge->value) {
            $links += $this->chargeCreditSummary($id);
        }
        return $links;
    }

    public function creditBalance(EntityId $source, Money $amount): Money
    {
        if (!$this->canCreditCharge($source)) {
            throw new ValidationException([
                [
                    "field" => "source_operation_id",
                    "message" => "Choose a posted charge with an amount still available to credit.",
                ],
            ]);
        }
        $entry = $this->operations[$source->value];
        if (
            $amount->minor < 1 ||
            $amount->currency !== $entry["currency"] ||
            $amount->minor > $this->chargeCreditSummary($source->value)["remaining_charge_minor"]
        ) {
            throw new ValidationException([
                [
                    "field" => "amount_minor",
                    "message" =>
                        "Enter a positive credit no greater than the remaining charge, in its original currency.",
                ],
            ]);
        }
        $minor = $this->balance->minor - $amount->minor;
        if ($minor < -Money::MAX_MINOR) {
            throw new ValidationException([
                [
                    "field" => "amount_minor",
                    "message" => "The credited balance exceeds the supported account limit.",
                ],
            ]);
        }
        return new Money($minor, $amount->currency);
    }

    /** @return LinkedEntryResult|null */
    public function chargeCreditResult(EntityId $operation, EntityId $actor, string $hash): ?array
    {
        $receipt = $this->chargeCreditReceipts[$operation->value] ?? null;
        if ($receipt === null) {
            return null;
        }
        if ($receipt["actor"] !== $actor->value || !hash_equals($receipt["hash"], $hash)) {
            throw new DomainException("This charge credit belongs to a different reviewed instruction.");
        }
        return $receipt["result"];
    }

    /** @return LinkedEntryResult */
    public function creditCharge(
        EntityId $source,
        EntityId $operation,
        Money $amount,
        EntityId $actor,
        string $hash,
    ): array {
        if (($accepted = $this->chargeCreditResult($operation, $actor, $hash)) !== null) {
            return $accepted;
        }
        $this->creditBalance($source, $amount);
        if (
            isset($this->operations[$operation->value]) ||
            isset($this->paymentDetailsReceipts[$operation->value]) ||
            preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
        ) {
            throw new DomainException("A new charge credit identity and reviewed instruction are required.");
        }
        $this->recordThat(
            AccountChargeWasCredited::occur(
                $this->aggregateId(),
                [
                    "source_operation_id" => $source->value,
                    "operation_id" => $operation->value,
                    "minor" => $amount->minor,
                    "currency" => $amount->currency,
                    "actor_id" => $actor->value,
                    "review_hash" => $hash,
                ],
                [Metadata::AGGREGATE_TYPE => "student_account"],
            ),
        );
        return $this->chargeCreditReceipts[$operation->value]["result"];
    }

    protected function whenAccountChargeWasCredited(AccountChargeWasCredited $event): void
    {
        $p = $event->payload();
        $source = new EntityId($p["source_operation_id"]);
        $amount = new Money($p["minor"], $p["currency"]);
        $balance = $this->creditBalance($source, $amount);
        if (
            isset($this->operations[$p["operation_id"]]) ||
            isset($this->paymentDetailsReceipts[$p["operation_id"]])
        ) {
            throw new DomainException("The recorded charge credit reuses an earlier instruction identity.");
        }
        $this->operations[$p["operation_id"]] = [
            "type" => EntryType::ChargeCredit->value,
            "minor" => $amount->minor,
            "currency" => $amount->currency,
        ];
        $this->chargeCreditSources[$p["operation_id"]] = $source->value;
        $this->creditsByCharge[$source->value][] = $p["operation_id"];
        $this->balance = $balance;
        $this->chargeCreditReceipts[$p["operation_id"]] = [
            "actor" => $p["actor_id"],
            "hash" => $p["review_hash"],
            "result" => [
                "status" => "credited",
                "operation_id" => $p["operation_id"],
                "source_operation_id" => $source->value,
                "balance_minor" => $balance->minor,
                "position" => $event->playhead(),
            ],
        ];
    }

    public function canCorrectAmount(EntityId $source): bool
    {
        return isset($this->staffRecorded[$source->value]) &&
        !isset($this->reversedOperations[$source->value]) &&
        $this->activeChargeCredits($source->value) === [];
    }

    public function correctedBalance(EntityId $source, Money $amount, ?RefundAllocation $allocation): Money
    {
        if (!$this->canCorrectAmount($source)) {
            throw new ValidationException([
                [
                    "field" => "source_operation_id",
                    "message" =>
                        "Only an unreversed, explicitly staff-recorded entry without active charge credits " .
                        "can be corrected here.",
                ],
            ]);
        }
        $entry = $this->operations[$source->value];
        if (
            $amount->currency !== $entry["currency"] ||
            $amount->minor < 1 ||
            ($amount->minor === $entry["minor"] &&
                $allocation?->values() === ($this->refundAllocations[$source->value] ?? null)?->values())
        ) {
            throw new ValidationException([
                [
                    "field" => "amount_minor",
                    "message" => "Enter a different positive amount in the original currency.",
                ],
            ]);
        }
        $sign = $entry["type"] === EntryType::Payment->value ? -1 : 1;
        $minor = $this->balance->minor + $sign * ($amount->minor - $entry["minor"]);
        if ($minor < -Money::MAX_MINOR || $minor > Money::MAX_MINOR) {
            throw new ValidationException([
                [
                    "field" => "amount_minor",
                    "message" => "The corrected balance exceeds the supported account limit.",
                ],
            ]);
        }
        $balance = new Money($minor, $amount->currency);
        if (
            $entry["type"] === EntryType::Refund->value &&
            $amount->minor > $entry["minor"] &&
            $balance->minor > 0
        ) {
            throw new ValidationException([
                [
                    "field" => "amount_minor",
                    "message" => "The corrected refund cannot exceed available credit.",
                ],
            ]);
        }
        if ($allocation !== null) {
            if ($entry["type"] !== EntryType::Refund->value) {
                throw new DomainException("Payment allocations belong to a refund.");
            }
            $this->validateAllocation($allocation, $amount, $source);
        } elseif (isset($this->refundAllocations[$source->value])) {
            throw new ValidationException([
                [
                    "field" => "refund_allocations",
                    "message" => "Keep or correct the recorded payment allocations.",
                ],
            ]);
        }
        return $balance;
    }

    /** @return LinkedEntryResult|null */
    public function correctionResult(EntityId $operation, EntityId $actor, string $reviewHash): ?array
    {
        $receipt = $this->corrections[$operation->value] ?? null;
        if ($receipt === null) {
            return null;
        }
        if ($receipt["actor"] !== $actor->value || !hash_equals($receipt["hash"], $reviewHash)) {
            throw new DomainException("This correction belongs to a different reviewed instruction.");
        }
        return $receipt["result"];
    }

    /** @return LinkedEntryResult */
    public function correct(
        EntityId $source,
        EntityId $operation,
        Money $amount,
        EntityId $actor,
        string $reviewHash,
        ?RefundAllocation $allocation,
    ): array {
        if (($result = $this->correctionResult($operation, $actor, $reviewHash)) !== null) {
            return $result;
        }
        $this->correctedBalance($source, $amount, $allocation);
        if (isset($this->operations[$operation->value]) || preg_match('/^[a-f0-9]{64}$/D', $reviewHash) !== 1) {
            throw new DomainException("A new correction identity and reviewed instruction are required.");
        }
        $receiptDetails = $this->paymentDetails($source);
        $this->recordThat(
            AccountEntryWasCorrected::occur(
                $this->aggregateId(),
                [
                    "source_operation_id" => $source->value,
                    "operation_id" => $operation->value,
                    "minor" => $amount->minor,
                    "currency" => $amount->currency,
                    "actor_id" => $actor->value,
                    "review_hash" => $reviewHash,
                ],
                [Metadata::AGGREGATE_TYPE => "student_account"] +
                    ($receiptDetails === null ? [] : ["payment_details" => $receiptDetails->values()]) +
                    ($allocation === null ? [] : ["refund_allocations" => $allocation->values()]),
            ),
        );
        return $this->corrections[$operation->value]["result"];
    }

    public function canCorrectPaymentDetails(EntityId $entry): bool
    {
        return isset($this->staffRecorded[$entry->value]) &&
        in_array($this->operations[$entry->value]["type"], ["payment", "refund"], true);
    }

    public function paymentDetails(EntityId $entry): ?PaymentDetails
    {
        $root = $this->receiptRoots[$entry->value] ?? null;
        return $root === null ? null : $this->paymentDetails[$root] ?? null;
    }

    public function assertPaymentDetailsChange(
        EntityId $entry,
        #[\SensitiveParameter] PaymentDetails $details,
    ): void {
        if (!$this->canCorrectPaymentDetails($entry)) {
            throw new ValidationException([
                [
                    "field" => "source_operation_id",
                    "message" =>
                        "Only explicitly staff-recorded payments or refunds support receipt corrections.",
                ],
            ]);
        }
        if (($this->paymentDetails($entry) ?? new PaymentDetails())->values() === $details->values()) {
            throw new ValidationException([
                [
                    "field" => "payment_reference",
                    "message" => "Change the payment method or receipt reference before reviewing.",
                ],
            ]);
        }
    }

    /** @return PaymentDetailsResult|null */
    public function paymentDetailsResult(EntityId $operation, EntityId $actor, string $hash): ?array
    {
        $receipt = $this->paymentDetailsReceipts[$operation->value] ?? null;
        if ($receipt === null) {
            return null;
        }
        if ($receipt["actor"] !== $actor->value || !hash_equals($receipt["hash"], $hash)) {
            throw new DomainException("This receipt correction belongs to a different reviewed instruction.");
        }
        return $receipt["result"];
    }

    /** @return PaymentDetailsResult */
    public function changePaymentDetails(
        EntityId $entry,
        EntityId $operation,
        #[\SensitiveParameter] PaymentDetails $details,
        EntityId $actor,
        string $hash,
    ): array {
        if (($accepted = $this->paymentDetailsResult($operation, $actor, $hash)) !== null) {
            return $accepted;
        }
        $this->assertPaymentDetailsChange($entry, $details);
        if (
            isset($this->operations[$operation->value]) ||
            isset($this->paymentDetailsReceipts[$operation->value]) ||
            preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
        ) {
            throw new DomainException("A new receipt correction identity and reviewed instruction are required.");
        }
        $this->recordThat(
            AccountPaymentDetailsWereChanged::occur(
                $this->aggregateId(),
                [
                    "operation_id" => $operation->value,
                    "entry_operation_id" => $entry->value,
                    "receipt_operation_id" => $this->receiptRoots[$entry->value],
                    "previous_details" => $this->paymentDetails($entry)?->values(),
                    "details" => $details->values(),
                    "actor_id" => $actor->value,
                    "review_hash" => $hash,
                ],
                [Metadata::AGGREGATE_TYPE => "student_account"],
            ),
        );
        return $this->paymentDetailsReceipts[$operation->value]["result"];
    }

    protected function whenAccountPaymentDetailsWereChanged(AccountPaymentDetailsWereChanged $event): void
    {
        $p = $event->payload();
        $entry = new EntityId($p["entry_operation_id"]);
        $details = PaymentDetails::fromRecorded($p["details"]);
        $this->assertPaymentDetailsChange($entry, $details);
        if (
            $p["receipt_operation_id"] !== $this->receiptRoots[$entry->value] ||
            $p["previous_details"] !== $this->paymentDetails($entry)?->values() ||
            isset($this->operations[$p["operation_id"]]) ||
            isset($this->paymentDetailsReceipts[$p["operation_id"]])
        ) {
            throw new DomainException("The recorded receipt correction does not match the preceding facts.");
        }
        $this->paymentDetails[$p["receipt_operation_id"]] = $details;
        $this->paymentDetailsReceipts[$p["operation_id"]] = [
            "actor" => $p["actor_id"],
            "hash" => $p["review_hash"],
            "result" => [
                "status" => "receipt_corrected",
                "operation_id" => $p["operation_id"],
                "entry_operation_id" => $entry->value,
                "balance_minor" => $this->balance->minor,
                "position" => $event->playhead(),
            ],
        ];
    }

    /** @return array{corrects_operation_id?:string,corrected_by_operation_id?:string} */
    private function correctionLinks(string $operation): array
    {
        $links = [];
        if (isset($this->corrections[$operation])) {
            $links["corrects_operation_id"] = $this->corrections[$operation]["result"]["source_operation_id"];
        }
        if (isset($this->correctedBy[$operation])) {
            $links["corrected_by_operation_id"] = $this->correctedBy[$operation];
        }
        return $links;
    }

    protected function whenAccountEntryWasCorrected(AccountEntryWasCorrected $event): void
    {
        $p = $event->payload();
        if (isset($this->operations[$p["operation_id"]])) {
            throw new DomainException("A correction cannot replace an existing entry identity.");
        }
        $source = new EntityId($p["source_operation_id"]);
        $amount = new Money($p["minor"], $p["currency"]);
        $allocation = isset($event->metadata()["refund_allocations"])
        ? new RefundAllocation($event->metadata()["refund_allocations"])
        : null;
        $balance = $this->correctedBalance($source, $amount, $allocation);
        $this->operations[$p["operation_id"]] = [
            "type" => $this->operations[$source->value]["type"],
            "minor" => $amount->minor,
            "currency" => $amount->currency,
        ];
        $this->receiptRoots[$p["operation_id"]] = $this->receiptRoots[$source->value];
        $this->changeRefundTotals($source->value, -1);
        if ($allocation !== null) {
            $this->refundAllocations[$p["operation_id"]] = $allocation;
            $this->changeRefundTotals($p["operation_id"], 1);
        }
        if ($this->operations[$source->value]["type"] === EntryType::Payment->value) {
            $this->activePaymentByReceipt[$this->receiptRoots[$source->value]] = $p["operation_id"];
        }
        $snapshot = $event->metadata()["payment_details"] ?? null;
        if (
            $snapshot !== null &&
            PaymentDetails::fromRecorded($snapshot)->values() !== $this->paymentDetails($source)?->values()
        ) {
            throw new DomainException("The corrected receipt snapshot does not match the preceding facts.");
        }
        if ($snapshot !== null) {
            $this->openingPaymentDetails[$p["operation_id"]] = PaymentDetails::fromRecorded($snapshot);
        }
        $this->balance = $balance;
        $this->staffRecorded[$p["operation_id"]] = true;
        $this->reversedOperations[$source->value] = true;
        $this->correctedBy[$source->value] = $p["operation_id"];
        $this->corrections[$p["operation_id"]] = [
            "actor" => $p["actor_id"],
            "hash" => $p["review_hash"],
            "result" => [
                "status" => "corrected",
                "operation_id" => $p["operation_id"],
                "source_operation_id" => $source->value,
                "balance_minor" => $balance->minor,
                "position" => $event->playhead(),
            ],
        ];
    }

    protected function whenAccountEntryWasReversed(AccountEntryWasReversed $event): void
    {
        $p = $event->payload();
        $operation = new EntityId($p["operation_id"]);
        $credits = $this->activeChargeCredits($operation->value);
        if (($event->metadata()["reversed_credit_ids"] ?? []) !== $credits) {
            throw new DomainException("The charge reversal does not retain its exact linked credit set.");
        }
        $this->balance = $this->reversalBalance($operation);
        foreach ($credits as $credit) {
            $this->reversedOperations[$credit] = true;
            $this->reversedWith[$credit] = $operation->value;
        }
        $this->reversedOperations[$operation->value] = true;
        if ($this->operations[$operation->value]["type"] === EntryType::Payment->value) {
            unset($this->activePaymentByReceipt[$this->receiptRoots[$operation->value]]);
        } elseif ($this->operations[$operation->value]["type"] === EntryType::Refund->value) {
            $this->changeRefundTotals($operation->value, -1);
        }
        if (isset($event->metadata()["review_hash"])) {
            $this->reversalReceipts[$operation->value] = [
                "actor" => $p["actor_id"],
                "hash" => $event->metadata()["review_hash"],
                "result" => [
                    "status" => "reversed",
                    "operation_id" => $operation->value,
                    "balance_minor" => $this->balance->minor,
                    "position" => $event->playhead(),
                ],
            ];
        }
    }

    protected function whenStudentAccountWasOpened(StudentAccountWasOpened $event): void
    {
        $this->studentId = new EntityId($event->payload()["student_id"]);
        $this->termId = new EntityId($event->payload()["term_id"]);
        $this->balance = new Money(0, $event->payload()["currency"]);
    }

    protected function whenAccountEntryWasPosted(AccountEntryWasPosted $event): void
    {
        $payload = $event->payload();
        $allocation = isset($event->metadata()["refund_allocations"])
        ? new RefundAllocation($event->metadata()["refund_allocations"])
        : null;
        if ($allocation !== null) {
            $this->postingBalance(
                EntryType::from($payload["type"]),
                new Money($payload["minor"], $payload["currency"]),
                $allocation,
            );
        }
        $this->receiptRoots[$payload["operation_id"]] = $payload["operation_id"];
        if (isset($event->metadata()["payment_details"])) {
            $details = PaymentDetails::fromRecorded($event->metadata()["payment_details"]);
            $this->paymentDetails[$payload["operation_id"]] = $details;
            $this->openingPaymentDetails[$payload["operation_id"]] = $details;
        }
        $this->operations[$payload["operation_id"]] = [
            "type" => $payload["type"],
            "minor" => $payload["minor"],
            "currency" => $payload["currency"],
        ];
        if ($payload["type"] === EntryType::Payment->value) {
            $this->activePaymentByReceipt[$payload["operation_id"]] = $payload["operation_id"];
        }
        if ($allocation !== null) {
            $this->refundAllocations[$payload["operation_id"]] = $allocation;
            $this->changeRefundTotals($payload["operation_id"], 1);
        }
        if (($event->metadata()["staff_recorded"] ?? false) === true) {
            $this->staffRecorded[$payload["operation_id"]] = true;
        }
        $delta = EntryType::from($payload["type"])->balanceSign() * $payload["minor"];
        $this->balance = $this->balance->add(new Money($delta, $payload["currency"]));
        if (isset($event->metadata()["review_hash"])) {
            $this->postingReceipts[$payload["operation_id"]] = [
                "actor" => $payload["actor_id"],
                "hash" => $event->metadata()["review_hash"],
                "result" => [
                    "status" => "posted",
                    "operation_id" => $payload["operation_id"],
                    "balance_minor" => $this->balance->minor,
                    "position" => $event->playhead(),
                ],
            ];
        }
    }
}
