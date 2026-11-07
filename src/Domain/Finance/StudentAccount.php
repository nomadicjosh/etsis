<?php

declare(strict_types=1);

namespace Domain\Finance;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Codefy\Domain\Metadata;
use DomainException;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Event\AccountEntryWasPosted;
use Domain\Finance\Event\AccountEntryWasReversed;
use Domain\Finance\Event\StudentAccountWasOpened;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;

final class StudentAccount extends EventSourcedAggregate
{
    private EntityId $studentId;
    private EntityId $termId;
    private Money $balance;
    /** @var array<string, array{type: string, minor: int, currency: string}> */
    private array $operations = [];
    /** @var array<string, true> */
    private array $reversedOperations = [];

    public static function open(AccountId $id, EntityId $studentId, EntityId $termId, string $currency): self
    {
        $money = new Money(0, $currency);
        $account = self::root($id);
        $account->recordThat(StudentAccountWasOpened::occur($id, [
            'student_id' => $studentId->value,
            'term_id' => $termId->value,
            'currency' => $money->currency,
        ], [Metadata::AGGREGATE_TYPE => 'student_account']));

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

    /** @return list<array{operation_id: string, type: string, minor: int, currency: string, reversed: bool}> */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->operations as $id => $entry) {
            $entries[] = ['operation_id' => $id] + $entry + ['reversed' => isset($this->reversedOperations[$id])];
        }

        return $entries;
    }

    public function post(EntityId $operationId, EntryType $type, Money $amount, EntityId $actorId): void
    {
        if ($amount->minor <= 0 || $amount->currency !== $this->balance->currency) {
            throw new DomainException('A positive amount in the account currency is required.');
        }
        $entry = ['type' => $type->value, 'minor' => $amount->minor, 'currency' => $amount->currency];
        if (isset($this->operations[$operationId->value])) {
            if ($this->operations[$operationId->value] !== $entry) {
                throw new DomainException('The operation identifier has already been used.');
            }
            return;
        }
        if ($type === EntryType::Refund && $amount->minor > -$this->balance->minor) {
            throw new DomainException('A refund cannot exceed available credit.');
        }
        $delta = $type === EntryType::Payment ? -$amount->minor : $amount->minor;
        $this->balance->add(new Money($delta, $amount->currency));
        $this->recordThat(AccountEntryWasPosted::occur($this->aggregateId(), $entry + [
            'operation_id' => $operationId->value,
            'actor_id' => $actorId->value,
        ], [Metadata::AGGREGATE_TYPE => 'student_account']));
    }

    public function reverse(EntityId $operationId, EntityId $actorId): void
    {
        $entry = $this->operations[$operationId->value]
        ?? throw new DomainException('The original entry does not exist.');
        if (isset($this->reversedOperations[$operationId->value])) {
            return;
        }
        $delta = $entry['type'] === EntryType::Payment->value ? $entry['minor'] : -$entry['minor'];
        $this->balance->add(new Money($delta, $entry['currency']));
        $this->recordThat(AccountEntryWasReversed::occur($this->aggregateId(), [
            'operation_id' => $operationId->value, 'actor_id' => $actorId->value,
        ], [Metadata::AGGREGATE_TYPE => 'student_account']));
    }

    protected function whenAccountEntryWasReversed(AccountEntryWasReversed $event): void
    {
        $operation = $event->payload()['operation_id'];
        $entry = $this->operations[$operation];
        $delta = $entry['type'] === EntryType::Payment->value ? $entry['minor'] : -$entry['minor'];
        $this->balance = $this->balance->add(new Money($delta, $entry['currency']));
        $this->reversedOperations[$operation] = true;
    }

    protected function whenStudentAccountWasOpened(StudentAccountWasOpened $event): void
    {
        $this->studentId = new EntityId($event->payload()['student_id']);
        $this->termId = new EntityId($event->payload()['term_id']);
        $this->balance = new Money(0, $event->payload()['currency']);
    }

    protected function whenAccountEntryWasPosted(AccountEntryWasPosted $event): void
    {
        $payload = $event->payload();
        $this->operations[$payload['operation_id']] = [
            'type' => $payload['type'],
            'minor' => $payload['minor'],
            'currency' => $payload['currency'],
        ];
        $delta = $payload['type'] === EntryType::Payment->value ? -$payload['minor'] : $payload['minor'];
        $this->balance = $this->balance->add(new Money($delta, $payload['currency']));
    }
}
