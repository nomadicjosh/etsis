<?php

declare(strict_types=1);

namespace Domain\Finance;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\Metadata;
use DomainException;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Event\AccountEntryWasPosted;
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
        return ["operation_id" => $operation->value] + $entry
        + ["reversed" => isset($this->reversedOperations[$operation->value])]
        + $this->correctionLinks($operation->value);
    }

    public function post(
        EntityId $operationId,
        EntryType $type,
        Money $amount,
        EntityId $actorId,
        bool $staffRecorded = false,
    ): void {
        if ($amount->minor <= 0 || $amount->currency !== $this->balance->currency) {
            throw new DomainException("A positive amount in the account currency is required.");
        }
        $entry = ["type" => $type->value, "minor" => $amount->minor, "currency" => $amount->currency];
        if (isset($this->operations[$operationId->value])) {
            if ($this->operations[$operationId->value] !== $entry) {
                throw new DomainException("The operation identifier has already been used.");
            }
            return;
        }
        if ($type === EntryType::Refund && $amount->minor > -$this->balance->minor) {
            throw new DomainException("A refund cannot exceed available credit.");
        }
        $delta = $type === EntryType::Payment ? -$amount->minor : $amount->minor;
        $this->balance->add(new Money($delta, $amount->currency));
        $this->recordThat(
            AccountEntryWasPosted::occur(
                $this->aggregateId(),
                $entry + [
                    "operation_id" => $operationId->value,
                    "actor_id" => $actorId->value,
                ],
                [Metadata::AGGREGATE_TYPE => "student_account"] +
                    ($staffRecorded ? ["staff_recorded" => true] : []),
            ),
        );
    }

    public function reverse(EntityId $operationId, EntityId $actorId): void
    {
        $entry =
        $this->operations[$operationId->value] ??
        throw new DomainException("The original entry does not exist.");
        if (isset($this->reversedOperations[$operationId->value])) {
            return;
        }
        $delta = $entry["type"] === EntryType::Payment->value ? $entry["minor"] : -$entry["minor"];
        $this->balance->add(new Money($delta, $entry["currency"]));
        $this->recordThat(
            AccountEntryWasReversed::occur(
                $this->aggregateId(),
                [
                    "operation_id" => $operationId->value,
                    "actor_id" => $actorId->value,
                ],
                [Metadata::AGGREGATE_TYPE => "student_account"],
            ),
        );
    }

    public function canCorrectAmount(EntityId $source): bool
    {
        return isset($this->staffRecorded[$source->value]) && !isset($this->reversedOperations[$source->value]);
    }

    public function correctedBalance(EntityId $source, Money $amount): Money
    {
        if (!$this->canCorrectAmount($source)) {
            throw new ValidationException([['field' => 'source_operation_id',
                'message' => 'Only an unreversed, explicitly staff-recorded entry can be corrected here.']]);
        }
        $entry = $this->operations[$source->value];
        if ($amount->currency !== $entry["currency"] || $amount->minor < 1 || $amount->minor === $entry["minor"]) {
            throw new ValidationException([['field' => 'amount_minor',
                'message' => 'Enter a different positive amount in the original currency.']]);
        }
        $sign = $entry["type"] === EntryType::Payment->value ? -1 : 1;
        $minor = $this->balance->minor + $sign * ($amount->minor - $entry["minor"]);
        if ($minor < -Money::MAX_MINOR || $minor > Money::MAX_MINOR) {
            throw new ValidationException([['field' => 'amount_minor',
                'message' => 'The corrected balance exceeds the supported account limit.']]);
        }
        $balance = new Money($minor, $amount->currency);
        if ($entry["type"] === EntryType::Refund->value && $balance->minor > 0) {
            throw new ValidationException([['field' => 'amount_minor',
                'message' => 'The corrected refund cannot exceed available credit.']]);
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
        if (isset($this->operations[$operation->value]) || preg_match('/^[a-f0-9]{64}$/D', $reviewHash) !== 1) {
            throw new DomainException("A new correction identity and reviewed instruction are required.");
        }
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
                [Metadata::AGGREGATE_TYPE => "student_account"],
            ),
        );
        return $this->corrections[$operation->value]["result"];
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
        $operation = $event->payload()["operation_id"];
        $entry = $this->operations[$operation];
        $delta = $entry["type"] === EntryType::Payment->value ? $entry["minor"] : -$entry["minor"];
        $this->balance = $this->balance->add(new Money($delta, $entry["currency"]));
        $this->reversedOperations[$operation] = true;
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
    }
}
