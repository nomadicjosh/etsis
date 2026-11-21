<?php

declare(strict_types=1);

namespace Tests\Unit;

use Codefy\Domain\EventSourcing\EventStream;
use Codefy\Domain\Metadata;
use DomainException;
use Domain\Finance\Enum\ChargeSource;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Event\AccountEntryWasPosted;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChargeSourceTest extends TestCase
{
    public function testEarlierStaffPostingKeepsUnknownSourceWhenRetriedWithCurrentCode(): void
    {
        $id = new AccountId();
        $root = StudentAccount::open($id, EntityId::generate(), EntityId::generate(), 'USD');
        $operation = EntityId::generate();
        $actor = EntityId::generate();
        $events = iterator_to_array($root->getRecordedEvents());
        $event = AccountEntryWasPosted::occur($id, [
            'operation_id' => $operation->value, 'type' => 'charge', 'minor' => 1000,
            'currency' => 'USD', 'actor_id' => $actor->value,
        ], [Metadata::AGGREGATE_TYPE => 'student_account', Metadata::AGGREGATE_PLAYHEAD => 2,
            'staff_recorded' => true]);
        new HistoricalSchemaRegistry()->describe($event);
        $events[] = $event;
        $root = StudentAccount::reconstituteFromEventStream(new EventStream($id, $events));
        self::assertTrue($root->canCorrectAmount($operation));
        self::assertArrayNotHasKey('charge_source', $root->entry($operation));
        $root->post($operation, EntryType::Charge, new Money(1000, 'USD'), $actor, staffRecorded: true);
        self::assertFalse($root->hasRecordedEvents());
        self::assertArrayNotHasKey('charge_source', $root->entry($operation));
    }

    public static function invalidSources(): iterable
    {
        yield 'unknown category' => ['untrusted', 'charge', false];
        yield 'non-scalar' => [['term_fee'], 'charge', false];
        yield 'payment' => ['term_fee', 'payment', false];
        yield 'staff automatic' => ['term_tuition', 'charge', true];
        yield 'automatic manual' => ['manual_charge', 'charge', false];
        yield 'credit rate without price' => ['credit_tuition', 'charge', false];
    }

    #[DataProvider('invalidSources')]
    public function testProtectedSchemaRejectsMisclassifiedPostings(mixed $source, string $type, bool $staff): void
    {
        $event = AccountEntryWasPosted::occur(new AccountId(), [
            'operation_id' => EntityId::generate()->value, 'type' => $type, 'minor' => 1000,
            'currency' => 'USD', 'actor_id' => EntityId::generate()->value,
        ], [Metadata::AGGREGATE_TYPE => 'student_account', 'charge_source' => $source] +
            ($staff ? ['staff_recorded' => true] : []));
        $this->expectException(CorruptHistoryException::class);
        new HistoricalSchemaRegistry()->describe($event);
    }

    public function testKnownSourceCannotChangeWhenAnOperationIsRetried(): void
    {
        $root = StudentAccount::open(new AccountId(), EntityId::generate(), EntityId::generate(), 'USD');
        $op = EntityId::generate();
        $actor = EntityId::generate();
        $root->post($op, EntryType::Charge, new Money(1000, 'USD'), $actor, chargeSource: ChargeSource::TermFee);
        $root->clearRecordedEvents();
        $this->expectException(DomainException::class);
        $root->post($op, EntryType::Charge, new Money(1000, 'USD'), $actor, chargeSource: ChargeSource::TermTuition);
    }
}
