<?php

declare(strict_types=1);

use Codefy\Domain\EventSourcing\EventStream;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class StudentAccountTest extends TestCase
{
    public function testChargesPaymentsAndRefundsSurviveReplay(): void
    {
        $id = new AccountId();
        $account = StudentAccount::open($id, EntityId::generate(), EntityId::generate(), 'USD');
        $actor = EntityId::generate();
        $account->post(EntityId::generate(), EntryType::Charge, new Money(10000, 'USD'), $actor);
        $account->post(EntityId::generate(), EntryType::Payment, new Money(15000, 'USD'), $actor);
        $account->post(EntityId::generate(), EntryType::Refund, new Money(2000, 'USD'), $actor);
        $replayed = StudentAccount::reconstituteFromEventStream(new EventStream(
            $id, iterator_to_array($account->getRecordedEvents()),
        ));
        self::assertSame(-3000, $replayed->balance()->minor);
        self::assertFalse($replayed->hasRecordedEvents());
    }

    public function testOverRefundIsRejectedWithoutRecordingAnEvent(): void
    {
        $account = StudentAccount::open(new AccountId(), EntityId::generate(), EntityId::generate(), 'USD');
        $account->clearRecordedEvents();
        try {
            $account->post(EntityId::generate(), EntryType::Refund, new Money(1, 'USD'), EntityId::generate());
            self::fail('Refund without credit was accepted.');
        } catch (DomainException) {
            self::assertFalse($account->hasRecordedEvents());
        }
    }

    public function testOperationCannotBeReusedForDifferentAmount(): void
    {
        $account = StudentAccount::open(new AccountId(), EntityId::generate(), EntityId::generate(), 'USD');
        $operation = EntityId::generate();
        $actor = EntityId::generate();
        $account->post($operation, EntryType::Payment, new Money(100, 'USD'), $actor);
        $this->expectException(DomainException::class);
        $account->post($operation, EntryType::Payment, new Money(200, 'USD'), $actor);
    }

    public function testCurrenciesCannotBeMixed(): void
    {
        $account = StudentAccount::open(new AccountId(), EntityId::generate(), EntityId::generate(), 'USD');
        $this->expectException(DomainException::class);
        $account->post(EntityId::generate(), EntryType::Payment, new Money(100, 'EUR'), EntityId::generate());
    }
}
