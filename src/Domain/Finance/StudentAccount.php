<?php

declare(strict_types=1);

namespace Domain\Finance;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\Metadata;
use DomainException;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Event\AccountEntryWasPosted;
use Domain\Finance\Event\AccountPaymentDetailsWereChanged;
use Domain\Finance\ValueObject\PaymentDetails;
use Domain\Finance\Event\AccountEntryWasCorrected;
use Domain\Finance\Event\AccountEntryWasReversed;
use Domain\Finance\Event\StudentAccountWasOpened;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\ValidationException;

/**
 * @phpstan-type AccountEntry array{operation_id:string,type:string,minor:int,currency:string,reversed:bool,
 *     corrects_operation_id?:string,corrected_by_operation_id?:string}
 * @phpstan-type CorrectionResult array{status:string,operation_id:string,source_operation_id:string,
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
    /** @var array<string, array{actor:string,hash:string,result:CorrectionResult}> */
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
            $flags = ["reversed" => isset($this->reversedOperations[$id])] + $this->correctionLinks($id);
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
        $this->correctionLinks($operation->value);
    }

    public function post(
        EntityId $operationId,
        EntryType $type,
        Money $amount,
        EntityId $actorId,
        bool $staffRecorded = false,
        ?string $reviewHash = null,
        #[\SensitiveParameter] ?PaymentDetails $paymentDetails = null,
    ): void {
        if ($amount->minor <= 0 || $amount->currency !== $this->balance->currency) {
            throw new DomainException("A positive amount in the account currency is required.");
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
                    $paymentDetails?->values()
            ) {
                throw new DomainException("The operation identifier has already been used.");
            }
            return;
        }
        if ($reviewHash !== null && (!$staffRecorded || preg_match('/^[a-f0-9]{64}$/D', $reviewHash) !== 1)) {
            throw new DomainException("A reviewed staff posting requires a valid instruction hash.");
        }
        $this->postingBalance($type, $amount);
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
                    ($paymentDetails === null ? [] : ["payment_details" => $paymentDetails->values()]),
            ),
        );
    }

    public function postingBalance(EntryType $type, Money $amount): Money
    {
        if ($amount->minor <= 0 || $amount->currency !== $this->balance->currency) {
            throw new DomainException("A positive amount in the account currency is required.");
        }
        if ($type === EntryType::Refund && $amount->minor > -$this->balance->minor) {
            throw new DomainException("A refund cannot exceed available credit.");
        }
        $delta = $type === EntryType::Payment ? -$amount->minor : $amount->minor;
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
        );
        return $this->postingReceipts[$operation->value]["result"];
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
        $delta = $entry["type"] === EntryType::Payment->value ? $entry["minor"] : -$entry["minor"];
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
        $this->recordThat(
            AccountEntryWasReversed::occur(
                $this->aggregateId(),
                ["operation_id" => $operationId->value, "actor_id" => $actorId->value],
                [Metadata::AGGREGATE_TYPE => "student_account"] +
                    ($reviewHash === null ? [] : ["review_hash" => $reviewHash]),
            ),
        );
    }

    public function canCorrectAmount(EntityId $source): bool
    {
        return isset($this->staffRecorded[$source->value]) &&
        !isset($this->reversedOperations[$source->value]);
    }

    public function correctedBalance(EntityId $source, Money $amount): Money
    {
        if (!$this->canCorrectAmount($source)) {
            throw new ValidationException([
                [
                    "field" => "source_operation_id",
                    "message" => "Only an unreversed, explicitly staff-recorded entry can be corrected here.",
                ],
            ]);
        }
        $entry = $this->operations[$source->value];
        if (
            $amount->currency !== $entry["currency"] ||
            $amount->minor < 1 ||
            $amount->minor === $entry["minor"]
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
        if ($entry["type"] === EntryType::Refund->value && $balance->minor > 0) {
            throw new ValidationException([
                [
                    "field" => "amount_minor",
                    "message" => "The corrected refund cannot exceed available credit.",
                ],
            ]);
        }
        return $balance;
    }

    /** @return CorrectionResult|null */
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

    /** @return CorrectionResult */
    public function correct(
        EntityId $source,
        EntityId $operation,
        Money $amount,
        EntityId $actor,
        string $reviewHash,
    ): array {
        if (($result = $this->correctionResult($operation, $actor, $reviewHash)) !== null) {
            return $result;
        }
        $this->correctedBalance($source, $amount);
        if (
            isset($this->operations[$operation->value]) ||
            preg_match('/^[a-f0-9]{64}$/D', $reviewHash) !== 1
        ) {
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
                    ($receiptDetails === null ? [] : ["payment_details" => $receiptDetails->values()]),
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
            throw new DomainException(
                "A new receipt correction identity and reviewed instruction are required.",
            );
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
        $balance = $this->correctedBalance($source, $amount);
        $this->operations[$p["operation_id"]] = [
            "type" => $this->operations[$source->value]["type"],
            "minor" => $amount->minor,
            "currency" => $amount->currency,
        ];
        $this->receiptRoots[$p["operation_id"]] = $this->receiptRoots[$source->value];
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
        $this->balance = $this->reversalBalance($operation);
        $this->reversedOperations[$operation->value] = true;
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
        if (($event->metadata()["staff_recorded"] ?? false) === true) {
            $this->staffRecorded[$payload["operation_id"]] = true;
        }
        $delta = $payload["type"] === EntryType::Payment->value ? -$payload["minor"] : $payload["minor"];
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
