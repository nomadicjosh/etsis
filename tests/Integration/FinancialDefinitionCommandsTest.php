<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\BillingRule;
use Domain\Finance\Journal;
use Domain\Finance\LedgerLine;
use Domain\Finance\Repository\JournalRepository;
use Domain\Finance\Service\LedgerService;
use Domain\Finance\Service\TermBillingService;
use Domain\Finance\ValueObject\BillingRuleId;
use Domain\Finance\ValueObject\JournalId;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Service\RegistrationBilling;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository;
use Infrastructure\Persistence\Repository\EventSourcedJournalRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class FinancialDefinitionCommandsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function rules(SisFixture $s, ?AuditTrail $audit = null): TermBillingService
    {
        return new TermBillingService(
            $this->ruleRepository($s),
            $this->createStub(RegistrationBilling::class),
            $s->registration,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->financialCommands()
        );
    }

    private function ruleRepository(SisFixture $s): EventSourcedBillingRuleRepository
    {
        $db = $s->connection->queryBuilder();
        return new EventSourcedBillingRuleRepository(
            $s->sql,
            EventStoreFixture::framework($db),
            $s->transactions,
            EventStoreFixture::financialWriter($db)
        );
    }

    private function journalRepository(SisFixture $s): EventSourcedJournalRepository
    {
        $db = $s->connection->queryBuilder();
        return new EventSourcedJournalRepository(
            $s->sql,
            EventStoreFixture::framework($db),
            $s->transactions,
            EventStoreFixture::financialWriter($db)
        );
    }

    private function ledger(
        SisFixture $s,
        ?AuditTrail $audit = null,
        ?JournalRepository $repository = null,
    ): LedgerService {
        return new LedgerService(
            $repository ?? $this->journalRepository($s),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->financialCommands()
        );
    }

    /** @return list<LedgerLine> */
    private function lines(SisFixture $s): array
    {
        $service = $this->ledger($s);
        return [
            new LedgerLine($service->addAccount('AR', 'Fictional receivable', 'USD'), new Money(1000, 'USD')),
            new LedgerLine($service->addAccount('REV', 'Fictional revenue', 'USD'), new Money(-1000, 'USD')),
        ];
    }

    private function define(SisFixture $s, EntityId $operation, string $reason = 'Fictional term fee.'): BillingRule
    {
        return $this->rules($s)->define(
            $operation,
            $s->term,
            $s->program,
            'Registration fee',
            'fee',
            new Money(2500, 'USD'),
            $reason
        );
    }

    private function facts(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), ['sis_billing_rules', 'sis_journals', 'sis_ledger_accounts',
            'sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_audit']);
    }

    public function testRuleCreationAndRetirementPreserveTheirOriginalFactsAndProtectedContext(): void
    {
        $s = new SisFixture();
        $operation = EntityId::generate();
        $rule = $this->define($s, $operation);
        self::assertSame($operation->value, (string) $rule->aggregateId());
        $service = $this->rules($s);
        $before = $this->facts($s);
        try {
            $service->retire($rule->aggregateId(), 2, 'Stale retirement.');
            self::fail('A retirement ignored the displayed position.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($s));
        }
        $service->retire($rule->aggregateId(), 1, 'Retire a fictional fee.');
        $before = $this->facts($s);
        self::assertSame('already_recorded', $service->retire($rule->aggregateId(), 1, 'Retry reason.')['status']);
        self::assertTrue($this->define($s, $operation, 'Retry cannot replace the original reason.')->isRetired());
        self::assertSame($before, $this->facts($s));
        try {
            $service->define(
                $operation,
                $s->term,
                $s->program,
                'Changed fee',
                'fee',
                new Money(2500, 'USD'),
                'Different facts.'
            );
            self::fail('The same creation ID defined a different rule.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($s));
        }
        $events = EventStoreFixture::history($s->connection->queryBuilder())->page(
            new StreamReference($rule->aggregateId(), 'billing_rule')
        )->events;
        self::assertSame('Fictional term fee.', $events[0]->context->reason);
        self::assertSame('Retire a fictional fee.', $events[1]->context->reason);
        self::assertSame('command', $events[1]->context->origin);
        self::assertSame($s->access->actor->value, $events[0]->context->actor->value);
        self::assertSame($operation->value, $events[1]->context->causation->value);
        self::assertSame($s->clock->now()->format('U.u'), $events[1]->context->effectiveAt->format('U.u'));
        self::assertStringNotContainsString('Fictional term fee.', json_encode($s->sql->rows('sis_history_events')));
    }

    public function testJournalCreationAndReversalAreRetrySafeAndKeepPostingDateSeparateFromAcceptanceTime(): void
    {
        $s = new SisFixture();
        $lines = $this->lines($s);
        $operation = EntityId::generate();
        $service = $this->ledger($s);
        $on = new DateTimeImmutable('2026-07-01');
        $journal = $service->post($operation, 'FICT.1', $on, $lines, 'Fictional approved journal.');
        self::assertSame($operation->value, (string) $journal->aggregateId());
        $before = $this->facts($s);
        try {
            $service->reverse($journal->aggregateId(), 2, 'Stale reversal.');
            self::fail('A reversal ignored the displayed position.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($s));
        }
        $service->reverse($journal->aggregateId(), 1, 'Correct a fictional journal.');
        $before = $this->facts($s);
        self::assertTrue($service->post($operation, 'FICT.1', $on, $lines, 'Retry.')->isReversed());
        self::assertSame('already_recorded', $service->reverse($journal->aggregateId(), 1, 'Retry.')['status']);
        self::assertSame($before, $this->facts($s));
        foreach (
            [['OTHER', $on, $lines], ['FICT.1', $on->modify('+1 day'), $lines],
            ['FICT.1', $on, array_reverse($lines)]] as [$reference, $date, $changedLines]
        ) {
            try {
                $service->post($operation, $reference, $date, $changedLines, 'Changed facts.');
                self::fail('A retry changed the accepted journal.');
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $this->facts($s));
            }
        }
        $events = EventStoreFixture::history($s->connection->queryBuilder())->page(
            new StreamReference($journal->aggregateId(), 'journal')
        )->events;
        self::assertSame('2026-07-01', $events[0]->event->payload()['posted_on']);
        self::assertSame($s->clock->now()->format('U.u'), $events[0]->context->effectiveAt->format('U.u'));
        self::assertSame('Fictional approved journal.', $events[0]->context->reason);
        self::assertSame('Correct a fictional journal.', $events[1]->context->reason);
        self::assertSame($operation->value, $events[1]->context->correlation->value);
        self::assertSame($s->access->actor->value, $events[1]->context->actor->value);
    }

    public static function interruptedDecisions(): iterable
    {
        foreach (['define', 'retire', 'post', 'reverse', 'account'] as $action) {
            foreach (['audit', 'permission', 'actor'] as $failure) {
                yield $action . '/' . $failure => [$action, $failure];
            }
        }
    }

    #[DataProvider('interruptedDecisions')]
    public function testInterruptedDecisionsRollBackAllFinancialFacts(string $action, string $failure): void
    {
        $s = new SisFixture();
        $lines = $this->lines($s);
        $operation = EntityId::generate();
        $rule = $action === 'retire' ? $this->define($s, $operation) : null;
        $journal = $action === 'reverse' ? $this->ledger($s)->post(
            $operation,
            'FICT.1',
            new DateTimeImmutable('2026-08-01'),
            $lines,
            'Fictional journal.'
        ) : null;
        $other = $s->user();
        $before = $this->facts($s);
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
        try {
            match ($action) {
                'define' => $this->rules($s, $audit)->define($operation, $s->term, null, 'Fee', 'fee',
                    new Money(100, 'USD'), 'Fictional fee.'),
                'retire' => $this->rules($s, $audit)->retire($rule->aggregateId(), 1, 'Retire fee.'),
                'post' => $this->ledger($s, $audit)->post($operation, 'FICT.1', new DateTimeImmutable('2026-08-01'),
                    $lines, 'Fictional journal.'),
                'reverse' => $this->ledger($s, $audit)->reverse($journal->aggregateId(), 1, 'Reverse journal.'),
                'account' => $this->ledger($s, $audit)->addAccount('OTHER', 'Other fictional account', 'USD'),
            };
            self::fail('An interrupted financial decision committed.');
        } catch (\RuntimeException | AccessDeniedException) {
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testAcceptedRetriesStillRequireCurrentPermissionAndAValidReason(): void
    {
        $s = new SisFixture();
        $lines = $this->lines($s);
        $operation = EntityId::generate();
        $rule = $this->define($s, EntityId::generate());
        $service = $this->ledger($s);
        $on = new DateTimeImmutable('2026-08-01');
        $journal = $service->post($operation, 'FICT.1', $on, $lines, 'Fictional journal.');
        $before = $this->facts($s);
        foreach (['', str_repeat('x', 2001), "\0", "\xFF"] as $reason) {
            foreach (
                [fn () => $this->define($s, new EntityId((string) $rule->aggregateId()), $reason),
                fn () => $service->post($operation, 'FICT.1', $on, $lines, $reason)] as $retry
            ) {
                try {
                    $retry();
                    self::fail('An invalid reason was accepted on a financial retry.');
                } catch (ValidationException) {
                    self::assertSame($before, $this->facts($s));
                }
            }
        }
        $s->access->staff = false;
        $s->access->grants = ['sis:finance:read'];
        foreach (
            [fn () => $this->define($s, new EntityId((string) $rule->aggregateId())),
            fn () => $service->post($operation, 'FICT.1', $on, $lines, 'Retry.'),
            fn () => $this->rules($s)->retire($rule->aggregateId(), 1, 'Retire.'),
            fn () => $service->reverse($journal->aggregateId(), 1, 'Reverse.')] as $attempt
        ) {
            try {
                $attempt();
                self::fail('Read-only authority accepted a financial command.');
            } catch (AccessDeniedException) {
                self::assertSame($before, $this->facts($s));
            }
        }
    }

    public function testJournalReadAndPostUseAuthorityAndClockAfterHistoryRead(): void
    {
        $s = new SisFixture();
        $lines = $this->lines($s);
        $inner = $this->journalRepository($s);
        $repository = $this->createStub(JournalRepository::class);
        $repository->method('get')->willReturnCallback(static function (JournalId $id) use ($s, $inner): Journal {
            $s->clock->time = $s->clock->time->modify('+1 minute');
            return $inner->get($id);
        });
        $repository->method('ledgerCurrency')->willReturnCallback($inner->ledgerCurrency(...));
        $repository->method('save')->willReturnCallback($inner->save(...));
        $journal = $this->ledger($s, repository: $repository)->post(
            EntityId::generate(),
            'FICT.1',
            new DateTimeImmutable('2026-08-01'),
            $lines,
            'Fictional journal.'
        );
        $event = EventStoreFixture::history($s->connection->queryBuilder())->page(
            new StreamReference($journal->aggregateId(), 'journal')
        )->events[0];
        self::assertSame($s->clock->now()->format('U.u'), $event->context->effectiveAt->format('U.u'));
        $reader = $this->createStub(JournalRepository::class);
        $reader->method('get')->willReturnCallback(static function (JournalId $id) use ($s, $inner): Journal {
            $root = $inner->get($id);
            $s->access->staff = false;
            $s->access->grants = [];
            return $root;
        });
        $this->expectException(AccessDeniedException::class);
        $this->ledger($s, repository: $reader)->get($journal->aggregateId());
    }

    public function testEarlierFrameworkEventsAndReceiptsSurviveAttributedRetirementAndReversal(): void
    {
        foreach (['billing_rule', 'journal'] as $type) {
            $s = new SisFixture();
            if ($type === 'billing_rule') {
                $root = BillingRule::define(
                    new BillingRuleId(),
                    $s->term,
                    null,
                    'Earlier fee',
                    'fee',
                    new Money(100, 'USD'),
                    $s->access->actor
                );
                $s->sql->insert('sis_billing_rules', ['id' => (string) $root->aggregateId(),
                    'term_id' => $s->term->value, 'program_id' => null]);
            } else {
                $root = Journal::post(
                    new JournalId(),
                    'EARLIER',
                    new DateTimeImmutable('2026-07-01'),
                    $this->lines($s),
                    $s->access->actor
                );
                $s->sql->insert('sis_journals', ['id' => (string) $root->aggregateId(),
                    'reference' => $root->reference(), 'currency' => 'USD', 'posted_on' => $root->postedOn()]);
            }
            $db = $s->connection->queryBuilder();
            EventStoreFixture::framework($db)->commit(...iterator_to_array($root->getRecordedEvents()));
            $oldEvents = $s->sql->rows('sis_history_events');
            $oldReceipts = $s->sql->rows('sis_history_operations');
            if ($root instanceof BillingRule) {
                $this->rules($s)->retire($root->aggregateId(), 1, 'Retire an earlier fee.');
            } else {
                $this->ledger($s)->reverse($root->aggregateId(), 1, 'Reverse an earlier journal.');
            }
            self::assertSame($oldEvents, array_slice($s->sql->rows('sis_history_events'), 0, 1));
            self::assertSame($oldReceipts, array_slice($s->sql->rows('sis_history_operations'), 0, 1));
            $events = EventStoreFixture::history($db)->page(new StreamReference($root->aggregateId(), $type))->events;
            self::assertSame('framework', $events[0]->context->origin);
            self::assertNull($events[0]->context->effectiveAt);
            self::assertSame('command', $events[1]->context->origin);
        }
    }

    public function testRepositoriesRejectUnknownContextOrMismatchedActorsWithoutLeavingMetadata(): void
    {
        $s = new SisFixture();
        $roots = [BillingRule::define(
            new BillingRuleId(),
            $s->term,
            null,
            'Fee',
            'fee',
            new Money(100, 'USD'),
            $s->access->actor
        ),
            Journal::post(
                new JournalId(),
                'FICT.1',
                new DateTimeImmutable('2026-08-01'),
                $this->lines($s),
                $s->access->actor
            )];
        foreach ($roots as $root) {
            $repository = $root instanceof BillingRule ? $this->ruleRepository($s) : $this->journalRepository($s);
            foreach (
                [new EventContext(null, null, null, origin: 'framework'),
                new EventContext($s->access->actor, $s->clock->now(), EntityId::generate()),
                new EventContext(
                    $s->user(),
                    $s->clock->now(),
                    EntityId::generate(),
                    reason: 'Different actor.'
                )] as $context
            ) {
                $before = $this->facts($s);
                try {
                    $repository->save($root, $context);
                    self::fail('An unknown or inconsistent financial actor was accepted.');
                } catch (\InvalidArgumentException) {
                    self::assertSame($before, $this->facts($s));
                }
            }
        }
    }
}
