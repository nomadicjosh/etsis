<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\Service\StudentAccountService;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class FinancialCommandContextTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function facts(SisFixture $s): array
    {
        return [$s->sql->rows('sis_student_accounts'), $s->sql->rows('sis_account_balances'),
            $s->sql->rows('sis_history_events'), $s->sql->rows('sis_history_streams'),
            $s->sql->rows('sis_history_operations'), $s->sql->rows('sis_audit')];
    }

    private function history(SisFixture $s, AccountId $id): array
    {
        return \EventStoreFixture::history($s->connection->queryBuilder())
            ->page(new StreamReference($id, 'student_account'))->events;
    }

    private function account(SisFixture $s): AccountId
    {
        return $s->finance()->open($s->student()->id, $s->term, 'USD')->aggregateId();
    }

    public function testManualPostingAndReversalRetainTrustedProtectedCommandContext(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $service = $s->finance();
        $operation = EntityId::generate();
        $reason = "Fictional bank receipt.\nPrivate <script>literal</script> detail.";
        self::assertSame(['status' => 'posted'], $service->post(
            $id,
            $operation,
            EntryType::Payment,
            new Money(1234, 'USD'),
            1,
            $reason
        ));
        $events = $this->history($s, $id);
        self::assertSame('command', $events[0]->context->origin);
        self::assertSame($s->access->actor->value, $events[0]->context->actor->value);
        self::assertSame((string) $id, $events[0]->context->correlation->value);
        self::assertSame($reason, $events[1]->context->reason);
        self::assertSame($operation->value, $events[1]->context->correlation->value);
        self::assertSame($operation->value, $events[1]->context->causation->value);
        self::assertSame($s->clock->now()->format('U.u'), $events[1]->context->effectiveAt->format('U.u'));
        self::assertNull($events[1]->context->sourceTransactionId);
        self::assertStringNotContainsString($reason, json_encode($s->sql->rows('sis_history_events')));
        $service->reverse($id, $operation, 2, 'Correct a fictional duplicate bank receipt.');
        $last = $this->history($s, $id)[2];
        self::assertSame('Correct a fictional duplicate bank receipt.', $last->context->reason);
        self::assertSame($operation->value, $last->context->causation->value);
        self::assertSame(0, $s->accounts->get($id)->balance()->minor);
    }

    public function testStaleFormsCannotPostNewEntriesAndRetriesPreserveOriginalReasonsAndLaterActivity(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $service = $s->finance();
        $operation = EntityId::generate();
        $service->post($id, $operation, EntryType::Payment, new Money(1000, 'USD'), 1, 'Original receipt.');
        $before = $this->facts($s);
        try {
            $service->post($id, EntityId::generate(), EntryType::Charge, new Money(100, 'USD'), 1, 'Stale form.');
            self::fail('A stale financial form posted a new entry.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($s));
        }
        $service->post($id, EntityId::generate(), EntryType::Charge, new Money(100, 'USD'), 2, 'Later charge.');
        $before = $this->facts($s);
        self::assertSame(['status' => 'already_recorded'], $service->post(
            $id,
            $operation,
            EntryType::Payment,
            new Money(1000, 'USD'),
            1,
            'A retry cannot replace the earlier reason.'
        ));
        self::assertSame($before, $this->facts($s));
        self::assertSame('Original receipt.', $this->history($s, $id)[1]->context->reason);
        try {
            $service->post($id, $operation, EntryType::Payment, new Money(999, 'USD'), 1, 'Changed amount.');
            self::fail('An accepted operation ID was reused for different monetary facts.');
        } catch (\DomainException) {
            self::assertSame($before, $this->facts($s));
        }
        $service->reverse($id, $operation, 3, 'Original reversal.');
        $service->post($id, EntityId::generate(), EntryType::Charge, new Money(50, 'USD'), 4, 'Later charge.');
        $before = $this->facts($s);
        self::assertSame(['status' => 'already_recorded'], $service->reverse($id, $operation, 3, 'Retry explanation.'));
        self::assertSame($before, $this->facts($s));
        self::assertSame('Original reversal.', $this->history($s, $id)[3]->context->reason);
        self::assertSame(150, $s->accounts->get($id)->balance()->minor);
    }

    public function testReasonsAndCurrentPostingAuthorityAreRequiredEvenForAnAcceptedRetry(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $operation = EntityId::generate();
        $service = $s->finance();
        $before = $this->facts($s);
        foreach (['', "\0", str_repeat('x', 2001)] as $reason) {
            try {
                $service->post($id, $operation, EntryType::Payment, new Money(100, 'USD'), 1, $reason);
                self::fail('An invalid financial reason was accepted.');
            } catch (ValidationException) {
                self::assertSame($before, $this->facts($s));
            }
        }
        $service->post($id, $operation, EntryType::Payment, new Money(100, 'USD'), 1, 'Fictional payment.');
        $before = $this->facts($s);
        $s->access->staff = false;
        $s->access->grants = ['sis:finance:read'];
        try {
            $service->post($id, $operation, EntryType::Payment, new Money(100, 'USD'), 1, 'Fictional retry.');
            self::fail('Read access allowed a posting retry.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testAuditFailureOrLostAuthorityRollsBackEventsReceiptsAndProjectionsTogether(): void
    {
        foreach (['audit', 'permission', 'actor'] as $failure) {
            $s = new SisFixture();
            $id = $this->account($s);
            $before = $this->facts($s);
            $other = $s->user();
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($s, $failure, $other): void {
                if ($failure === 'audit') {
                    throw new \RuntimeException('Fictional required audit failure.');
                }
                if ($failure === 'permission') {
                    $s->access->staff = false;
                    $s->access->grants = [];
                } else {
                    $s->access->actor = $other;
                }
            });
            $service = new StudentAccountService(
                $s->accounts,
                $s->admissions,
                $s->access,
                $s->transactions,
                $s->clock,
                $audit
            );
            try {
                $service->post($id, EntityId::generate(), EntryType::Payment, new Money(100, 'USD'), 1, 'Receipt.');
                self::fail('An interrupted posting committed part of the financial decision.');
            } catch (\RuntimeException | AccessDeniedException) {
                self::assertSame($before, $this->facts($s));
            }
        }
    }

    public function testEffectiveTimeIsCapturedAfterAccountHistoryReadAndActorIsRechecked(): void
    {
        $s = new SisFixture();
        $id = $this->account($s);
        $inner = $s->accounts;
        $repository = $this->createStub(AccountRepository::class);
        $repository->method('get')->willReturnCallback(static function (AccountId $id) use (
            $s,
            $inner,
        ): StudentAccount {
            $root = $inner->get($id);
            $s->clock->time = $s->clock->time->modify('+1 minute');
            return $root;
        });
        $repository->method('save')->willReturnCallback($inner->save(...));
        $service = new StudentAccountService(
            $repository,
            $s->admissions,
            $s->access,
            $s->transactions,
            $s->clock,
            $s->audit
        );
        $service->post($id, EntityId::generate(), EntryType::Payment, new Money(100, 'USD'), 1, 'Receipt.');
        self::assertSame(
            $s->clock->now()->format('U.u'),
            $this->history($s, $id)[1]->context->effectiveAt->format('U.u')
        );
    }

    public function testRepositoryRejectsUnknownCommandContextAndDisagreeingActors(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $account = StudentAccount::open(new AccountId(), $student->id, $s->term, 'USD');
        foreach (
            [new EventContext($s->access->actor, $s->clock->now(), EntityId::generate()),
            new EventContext(null, null, null, origin: 'framework')] as $context
        ) {
            try {
                $s->accounts->save($account, $context);
                self::fail('An uncontextualized account write was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $s->sql->rows('sis_history_events'));
            }
        }
        $s->saveAccount($account);
        $before = $this->facts($s);
        $account->post(EntityId::generate(), EntryType::Payment, new Money(100, 'USD'), $s->access->actor);
        try {
            $s->accounts->save($account, new EventContext(
                $s->user(),
                $s->clock->now(),
                EntityId::generate(),
                reason: 'Bad actor.',
            ));
            self::fail('A different actor was recorded than the one in the financial event.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testEarlierFrameworkFactsAndReceiptsSurviveNewAttributedCommands(): void
    {
        $s = new SisFixture();
        $root = StudentAccount::open(new AccountId(), $s->student()->id, $s->term, 'USD');
        $root->post(EntityId::generate(), EntryType::Payment, new Money(100, 'USD'), $s->access->actor);
        \EventStoreFixture::framework($s->connection->queryBuilder())->commit(
            ...iterator_to_array($root->getRecordedEvents()),
        );
        $s->sql->insert('sis_student_accounts', ['id' => (string) $root->aggregateId(),
            'student_id' => $root->studentId()->value, 'term_id' => $root->termId()->value, 'currency' => 'USD']);
        new \Infrastructure\Projection\SqlAccountBalanceProjection($s->sql)->initialize($root);
        $oldEvents = $s->sql->rows('sis_history_events');
        $oldReceipts = $s->sql->rows('sis_history_operations');
        $s->finance()->post(
            $root->aggregateId(),
            EntityId::generate(),
            EntryType::Charge,
            new Money(20, 'USD'),
            2,
            'New attributed charge.'
        );
        self::assertSame($oldEvents, array_slice($s->sql->rows('sis_history_events'), 0, 2));
        self::assertSame($oldReceipts, array_slice($s->sql->rows('sis_history_operations'), 0, 2));
        $events = $this->history($s, $root->aggregateId());
        self::assertSame('framework', $events[0]->context->origin);
        self::assertNull($events[0]->context->actor);
        self::assertSame('command', $events[2]->context->origin);
        self::assertSame(-80, $s->accounts->get($root->aggregateId())->balance()->minor);
    }
}
