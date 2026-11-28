<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Finance\Enum\EntryType;
use Domain\Finance\Service\LedgerService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class StudentLedgerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function setupLedger(bool $period = true): array
    {
        $s = new SisFixture();
        $ledger = new LedgerService(
            $s->journalRepository(),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->financialCommands(),
            $s->ledgerControl(),
        );
        $revenue = $ledger->addAccount(
            "REV",
            "Fictional tuition revenue",
            "USD",
        );
        $bank = $ledger->addAccount("BANK", "Fictional bank", "USD");
        $service = $s->studentLedger();
        $mapping = $service->reviewMapping(
            "USD",
            "America/Los_Angeles",
            "AR",
            "Student accounts receivable",
            $revenue,
            $bank,
            $bank,
            [],
            [],
            "Approve fictional student-account mapping.",
        );
        $service->confirm($mapping["review_token"]);
        $periodId = null;
        if ($period) {
            $review = $service->reviewPeriod(
                "USD",
                "August 2026",
                "2026-08-01",
                "2026-08-31",
                "Open fictional period.",
            );
            $periodId = new EntityId(
                $service->confirm($review["review_token"])["id"],
            );
        }
        $account = $s->finance()->open($s->student()->id, $s->term, "USD");
        return [$s, $service, $periodId, $account, $ledger, $revenue, $bank];
    }

    private function post(
        SisFixture $s,
        AccountId $account,
        EntryType $type,
        int $amount,
    ): EntityId {
        $operation = EntityId::generate();
        $current = $s->finance()->account($account);
        $s->finance()->post(
            $account,
            $operation,
            $type,
            new Money($amount, "USD"),
            $current->playhead(),
            "Record fictional student activity.",
        );
        return $operation;
    }

    public function testStudentPostingsAreBalancedAndReconcileWithoutReplayingBusinessActions(): void
    {
        [$s, $service, $period, $account] = $this->setupLedger();
        $id = $account->aggregateId();
        $this->post($s, $id, EntryType::Charge, 10000);
        $this->post($s, $id, EntryType::Payment, 12000);
        $this->post($s, $id, EntryType::Refund, 1000);
        $before = $s->sql->rows("sis_history_events");
        $scan = $service->reconcile($period);
        self::assertTrue($scan["done"]);
        self::assertSame(3, $scan["checked"]);
        self::assertSame("-1000", $scan["ar_net_minor"]);
        self::assertCount(3, $s->sql->rows("sis_journals"));
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame(-1000, $s->finance()->account($id)->balance()->minor);
    }

    public function testClosedPeriodBlocksStudentAndManualWritesAndSupportsReasonedReopening(): void
    {
        [
            $s,
            $service,
            $period,
            $account,
            $ledger,
            $revenue,
            $bank,
        ] = $this->setupLedger();
        $id = $account->aggregateId();
        $this->post($s, $id, EntryType::Charge, 1000);
        $scan = $service->reconcile($period);
        $review = $service->reviewClosing(
            $period,
            false,
            $scan["continuation"],
            "Close reconciled fictional period.",
        );
        $accepted = $service->confirm($review["review_token"]);
        $before = $s->sql->rows("sis_history_events");
        foreach (["student", "manual"] as $kind) {
            try {
                if ($kind === "student") {
                    $this->post($s, $id, EntryType::Payment, 100);
                } else {
                    $ledger->post(
                        EntityId::generate(),
                        "MANUAL.1",
                        new \DateTimeImmutable("2026-08-10"),
                        [
                            new \Domain\Finance\LedgerLine(
                                $revenue,
                                new Money(100, "USD"),
                            ),
                            new \Domain\Finance\LedgerLine(
                                $bank,
                                new Money(-100, "USD"),
                            ),
                        ],
                        "Fictional manual entry.",
                    );
                }
                self::fail("A closed period accepted a monetary change.");
            } catch (\DomainException) {
                self::assertSame($before, $s->sql->rows("sis_history_events"));
            }
        }
        $s->clock->time = $s->clock->time->modify("+1 hour");
        self::assertSame($accepted, $service->confirm($review["review_token"]));
        $reopen = $service->reviewClosing(
            $period,
            true,
            null,
            "Reopen for an attributed accounting correction.",
        );
        $service->confirm($reopen["review_token"]);
        $this->post($s, $id, EntryType::Payment, 100);
        self::assertSame("900", $service->reconcile($period)["ar_net_minor"]);
    }

    public function testChangedSourcesInvalidateAClosingReview(): void
    {
        [$s, $service, $period, $account] = $this->setupLedger();
        $scan = $service->reconcile($period);
        $review = $service->reviewClosing(
            $period,
            false,
            $scan["continuation"],
            "Close reviewed period.",
        );
        $this->post($s, $account->aggregateId(), EntryType::Charge, 100);
        $this->expectException(ConcurrentModificationException::class);
        $service->confirm($review["review_token"]);
    }

    public function testMissingMovementCannotProduceAReconciledPeriod(): void
    {
        [$s, $service, $period, $account] = $this->setupLedger();
        $this->post($s, $account->aggregateId(), EntryType::Charge, 100);
        $s->sql->execute("DELETE FROM sis_gl_movements");
        $this->expectException(
            \Domain\Shared\EventSourcing\CorruptHistoryException::class,
        );
        $service->reconcile($period);
    }

    public function testEarlierStudentActivityCannotBeSilentlyAdoptedAtActivation(): void
    {
        $s = new SisFixture();
        $account = $s->finance()->open($s->student()->id, $s->term, "USD");
        $this->post($s, $account->aggregateId(), EntryType::Charge, 100);
        $id = EntityId::generate();
        $s->journalRepository()->addLedgerAccount(
            $id,
            "TEMP",
            "Fictional target",
            "USD",
        );
        $before = $s->sql->rows("sis_history_events");
        try {
            $s->studentLedger()->reviewMapping(
                "USD",
                "UTC",
                "AR",
                "Receivable",
                $id,
                $id,
                $id,
                [],
                [],
                "Attempt an implicit historical conversion.",
            );
            self::fail("Earlier activity was silently adopted.");
        } catch (\DomainException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
        }
    }

    public function testCreditsAndCorrectionsRetainTheirOriginalCounterpartAfterMappingChanges(): void
    {
        [
            $s,
            $service,
            $period,
            $account,
            $ledger,
            $revenue,
            $bank,
        ] = $this->setupLedger();
        $id = $account->aggregateId();
        $charge = $this->post($s, $id, EntryType::Charge, 1000);
        $replacementRevenue = $ledger->addAccount(
            "REV2",
            "Later fictional revenue",
            "USD",
        );
        $mapping = $service->reviewMapping(
            "USD",
            "America/Los_Angeles",
            "AR-NEW",
            "Renamed receivable",
            $replacementRevenue,
            $bank,
            $bank,
            [],
            [],
            "Publish a future counterpart.",
        );
        $service->confirm($mapping["review_token"]);
        $aggregate = $s->accounts->get($id);
        $correction = EntityId::generate();
        $aggregate->correct(
            $charge,
            $correction,
            new Money(1500, "USD"),
            $s->access->actor,
            hash("sha256", "fictional correction"),
            null,
        );
        $s->saveAccount($aggregate);
        $aggregate = $s->accounts->get($id);
        $credit = EntityId::generate();
        $aggregate->creditCharge(
            $correction,
            $credit,
            new Money(200, "USD"),
            $s->access->actor,
            hash("sha256", "fictional credit"),
        );
        $s->saveAccount($aggregate);
        self::assertSame("1300", $service->reconcile($period)["ar_net_minor"]);
        $movement = $s->sql->one("sis_gl_movements", [
            "account_id" => (string) $id,
            "entry_operation" => $credit->value,
        ]);
        $journal = $s
            ->journalRepository()
            ->get(
                new \Domain\Finance\ValueObject\JournalId(
                    $movement["journal_id"],
                ),
            );
        self::assertSame(
            $revenue->value,
            $journal->lines()[1]->accountId->value,
        );
        self::assertNotSame(
            $replacementRevenue->value,
            $journal->lines()[1]->accountId->value,
        );
        $aggregate = $s->accounts->get($id);
        $aggregate->reverse($correction, $s->access->actor);
        $s->saveAccount($aggregate);
        self::assertSame("0", $service->reconcile($period)["ar_net_minor"]);
        self::assertSame(0, $s->finance()->account($id)->balance()->minor);
    }

    public function testLaterPeriodReversalDoesNotRewriteTheClosedPeriod(): void
    {
        [$s, $service, $august, $account] = $this->setupLedger();
        $id = $account->aggregateId();
        $charge = $this->post($s, $id, EntryType::Charge, 1000);
        $scan = $service->reconcile($august);
        $review = $service->reviewClosing(
            $august,
            false,
            $scan["continuation"],
            "Close August.",
        );
        $service->confirm($review["review_token"]);
        $open = $service->reviewPeriod(
            "USD",
            "September",
            "2026-09-01",
            "2026-09-30",
            "Open September.",
        );
        $september = new EntityId(
            $service->confirm($open["review_token"])["id"],
        );
        $s->clock->time = new \DateTimeImmutable("2026-09-05T10:00:00-07:00");
        $s->finance()->reverse(
            $id,
            $charge,
            $s->accounts->get($id)->playhead(),
            "Correct in the new open period.",
        );
        self::assertSame("1000", $service->reconcile($august)["ar_net_minor"]);
        self::assertSame(
            "-1000",
            $service->reconcile($september)["ar_net_minor"],
        );
    }

    public function testFinalAuditFailureRollsBackTheAccountingDecision(): void
    {
        [$s, $service, $period] = $this->setupLedger();
        $scan = $service->reconcile($period);
        $review = $service->reviewClosing(
            $period,
            false,
            $scan["continuation"],
            "Attempt closing.",
        );
        $failing = $s->studentLedger(
            new class implements \Domain\Audit\Repository\AuditTrail {
                public function record(
                    EntityId $actor,
                    string $action,
                    EntityId $subject,
                ): void {
                    throw new \RuntimeException(
                        "Fictional mandatory audit failure.",
                    );
                }
                public function forSubject(EntityId $subject): array
                {
                    return [];
                }
            },
        );
        $before = $s->sql->rows("sis_history_events");
        try {
            $failing->confirm($review["review_token"]);
            self::fail("The accounting decision escaped its audit.");
        } catch (\RuntimeException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
            self::assertFalse(
                $s->ledgerControl()->period($period->value)["definition"][
                    "closed"
                ],
            );
        }
    }

    public function testReconciliationUsesBoundedPagesAndDetectsInterveningPostings(): void
    {
        [$s, $service, $period, $account] = $this->setupLedger();
        for ($i = 0; $i < 23; $i++) {
            $this->post($s, $account->aggregateId(), EntryType::Charge, 1);
        }
        $scan = $service->reconcile($period);
        self::assertFalse($scan["done"]);
        self::assertCount(20, $scan["items"]);
        $finished = $service->reconcile($period, $scan["continuation"]);
        self::assertTrue($finished["done"]);
        self::assertSame("23", $finished["ar_net_minor"]);
        $this->post($s, $account->aggregateId(), EntryType::Charge, 1);
        $this->expectException(ConcurrentModificationException::class);
        $service->reconcile($period, $scan["continuation"]);
    }

    public function testNoOpenPeriodLeavesTheAccountAndJournalUnchanged(): void
    {
        [$s, , , $account] = $this->setupLedger(false);
        $before = $s->sql->rows("sis_history_events");
        try {
            $this->post($s, $account->aggregateId(), EntryType::Charge, 1000);
            self::fail("Posting without an accounting period succeeded.");
        } catch (\DomainException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
            self::assertSame([], $s->sql->rows("sis_journals"));
            self::assertSame(
                0,
                $s->accounts->get($account->aggregateId())->balance()->minor,
            );
        }
    }

    public function testRemovingTheMappingProjectionCannotDisableAutomaticPosting(): void
    {
        [$s, , , $account] = $this->setupLedger(false);
        $s->sql->execute("DELETE FROM sis_gl_books");
        $before = $s->sql->rows("sis_history_events");
        try {
            $this->post($s, $account->aggregateId(), EntryType::Charge, 1000);
            self::fail("Deleted routing data disabled a published mapping.");
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
        }
    }

    public function testClosingRequiresIndependentCurrentAuthority(): void
    {
        [$s, $service, $period] = $this->setupLedger();
        $scan = $service->reconcile($period);
        $review = $service->reviewClosing(
            $period,
            false,
            $scan["continuation"],
            "Close with explicit authority.",
        );
        $s->access->staff = false;
        $s->access->grants = ["sis:finance:ledger", "sis:finance:read"];
        $this->expectException(
            \Domain\Shared\Exception\AccessDeniedException::class,
        );
        $service->confirm($review["review_token"]);
    }

    public function testBackdatedBusinessEffectUsesTheCurrentAccountingDateWithoutReopeningEarlierPeriods(): void
    {
        [$s, $service, $august, $account] = $this->setupLedger();
        $scan = $service->reconcile($august);
        $close = $service->reviewClosing($august, false, $scan['continuation'], 'Close the empty prior period.');
        $service->confirm($close['review_token']);
        $open = $service->reviewPeriod('USD', 'September', '2026-09-01', '2026-09-30', 'Open the current period.');
        $september = new EntityId($service->confirm($open['review_token'])['id']);
        $s->clock->time = new \DateTimeImmutable('2026-09-10T10:00:00-07:00');
        $aggregate = $s->accounts->get($account->aggregateId());
        $aggregate->post(
            EntityId::generate(),
            EntryType::Charge,
            new Money(100, 'USD'),
            $s->access->actor,
            staffRecorded: true
        );
        $s->accounts->save($aggregate, new \Domain\Shared\EventSourcing\EventContext(
            $s->access->actor,
            new \DateTimeImmutable('2026-08-20T10:00:00-07:00'),
            EntityId::generate(),
            reason: 'Record an earlier business effect today.'
        ));
        self::assertSame('0', $service->reconcile($august)['ar_net_minor']);
        $scan = $service->reconcile($september);
        self::assertSame('100', $scan['ar_net_minor']);
        self::assertSame('2026-09-10', $scan['items'][0]['posted_on']);
    }

    public function testDeletingPostingRowsCannotMakeExistingFinancialRecordsEligibleForActivation(): void
    {
        $s = new SisFixture();
        $account = $s->finance()->open($s->student()->id, $s->term, 'USD');
        $this->post($s, $account->aggregateId(), EntryType::Charge, 100);
        $s->sql->execute('DELETE FROM sis_account_entries');
        $target = EntityId::generate();
        $s->journalRepository()->addLedgerAccount($target, 'REVIEW', 'Fictional target', 'USD');
        $this->expectException(\Domain\Finance\Exception\AccountingControlException::class);
        $s->studentLedger()->reviewMapping(
            'USD',
            'UTC',
            'AR',
            'Receivable',
            $target,
            $target,
            $target,
            [],
            [],
            'Review the actual protected financial boundary.'
        );
    }

    public function testManualJournalReversalsParticipateInThePeriodCheckpointButCannotUseReceivables(): void
    {
        [$s, $service, $period, , $ledger, $revenue, $bank] = $this->setupLedger();
        $journal = $ledger->post(EntityId::generate(), 'MANUAL.REVIEW', new \DateTimeImmutable('2026-08-10'), [
            new \Domain\Finance\LedgerLine($revenue, new Money(100, 'USD')),
            new \Domain\Finance\LedgerLine($bank, new Money(-100, 'USD')),
        ], 'Record fictional balanced manual journal.');
        $ledger->reverse($journal->aggregateId(), 1, 'Reverse fictional manual journal.');
        $scan = $service->reconcile($period);
        self::assertTrue($scan['done']);
        self::assertSame(2, $scan['checked']);
        self::assertSame('0', $scan['ar_net_minor']);
        $ar = new EntityId($service->overview('USD')['book']['definition']['receivable']['id']);
        $before = $s->sql->rows('sis_history_events');
        try {
            $ledger->post(EntityId::generate(), 'MANUAL.BYPASS', new \DateTimeImmutable('2026-08-10'), [
                new \Domain\Finance\LedgerLine($ar, new Money(100, 'USD')),
                new \Domain\Finance\LedgerLine($bank, new Money(-100, 'USD')),
            ], 'Attempt manual receivable bypass.');
            self::fail('Manual posting changed controlled student receivables.');
        } catch (\Domain\Finance\Exception\AccountingControlException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
        }
    }

    public function testRegistrationAndItsAcademicFactsRollBackWhenTheAccountingPeriodIsClosed(): void
    {
        [$s, $service, $period, $account] = $this->setupLedger();
        $section = $s->section(fee: 500);
        $s->service->register($account->studentId(), $section->id);
        $scan = $service->reconcile($period);
        self::assertSame('500', $scan['ar_net_minor']);
        $beforeRetry = $s->sql->rows('sis_history_events');
        $s->service->register($account->studentId(), $section->id);
        self::assertSame($beforeRetry, $s->sql->rows('sis_history_events'));
        $close = $service->reviewClosing(
            $period,
            false,
            $scan['continuation'],
            'Close after the accepted registration.'
        );
        $service->confirm($close['review_token']);
        $nextSection = $s->section(fee: 700);
        $before = [$s->sql->rows('sis_history_events'), $s->sql->rows('sis_enrollments'),
            $s->sql->rows('sis_academic_credits'), $s->sql->rows('sis_journals')];
        try {
            $s->service->register($account->studentId(), $nextSection->id);
            self::fail('Registration escaped its closed accounting period.');
        } catch (\Domain\Finance\Exception\AccountingControlException) {
            self::assertSame($before, [$s->sql->rows('sis_history_events'), $s->sql->rows('sis_enrollments'),
                $s->sql->rows('sis_academic_credits'), $s->sql->rows('sis_journals')]);
        }
    }

    public function testChangedReferenceCurrencyRollsBackTheStudentAndAutomaticJournalTogether(): void
    {
        [$s, , , $account, , $revenue] = $this->setupLedger();
        $s->sql->execute('UPDATE sis_ledger_accounts SET currency = ? WHERE id = ?', ['EUR', $revenue->value]);
        $before = array_map($s->sql->rows(...), [
            'sis_history_events', 'sis_account_entries', 'sis_account_balances', 'sis_journals', 'sis_gl_movements',
        ]);
        try {
            $this->post($s, $account->aggregateId(), EntryType::Charge, 100);
            self::fail('A reference-currency change detached the protected monetary decision.');
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($before, array_map($s->sql->rows(...), [
                'sis_history_events', 'sis_account_entries', 'sis_account_balances', 'sis_journals', 'sis_gl_movements',
            ]));
        }
    }

    public function testMaximumAmountCorrectionCanOffsetAndReplaceWithoutLoweringTheStudentLimit(): void
    {
        [$s, $service, $period, $account] = $this->setupLedger();
        $id = $account->aggregateId();
        $charge = $this->post($s, $id, EntryType::Charge, Money::MAX_MINOR);
        $aggregate = $s->accounts->get($id);
        $correction = EntityId::generate();
        $aggregate->correct(
            $charge,
            $correction,
            new Money(Money::MAX_MINOR - 1, 'USD'),
            $s->access->actor,
            hash('sha256', 'Fictional maximum valid correction.'),
            null,
        );
        $s->saveAccount($aggregate);
        self::assertSame('1999999999', $service->reconcile($period)['ar_net_minor']);
        self::assertSame(Money::MAX_MINOR - 1, $s->accounts->get($id)->balance()->minor);
    }

    public function testSourceAndReceiptMethodOverridesSelectTheirPublishedCounterparts(): void
    {
        [$s, $service, $period, $account, $ledger, $revenue, $bank] = $this->setupLedger();
        $manual = $ledger->addAccount('MANUAL', 'Fictional manual charges', 'USD');
        $card = $ledger->addAccount('CARD', 'Fictional card clearing', 'USD');
        $review = $service->reviewMapping(
            'USD',
            'America/Los_Angeles',
            'AR',
            'Student accounts receivable',
            $revenue,
            $bank,
            $bank,
            [['key' => 'manual_charge', 'account_id' => $manual->value]],
            [['key' => 'card', 'account_id' => $card->value]],
            'Review fictional source and method counterparts.',
        );
        $service->confirm($review['review_token']);
        $id = $account->aggregateId();
        $charge = $this->post($s, $id, EntryType::Charge, 100);
        $payment = EntityId::generate();
        $s->finance()->post(
            $id,
            $payment,
            EntryType::Payment,
            new Money(100, 'USD'),
            $s->accounts->get($id)->playhead(),
            'Record a fictional card receipt.',
            new \Domain\Finance\ValueObject\PaymentDetails(\Domain\Finance\Enum\PaymentMethod::Card),
        );
        foreach ([[$charge, $manual], [$payment, $card]] as [$operation, $counterpart]) {
            $movement = $s->sql->one('sis_gl_movements', [
                'account_id' => (string) $id,
                'entry_operation' => $operation->value,
            ]);
            $journal = $s->journalRepository()->get(
                new \Domain\Finance\ValueObject\JournalId($movement['journal_id'])
            );
            self::assertSame($counterpart->value, $journal->lines()[1]->accountId->value);
        }
        self::assertSame('0', $service->reconcile($period)['ar_net_minor']);
    }

    public static function damagedEvidence(): iterable
    {
        yield 'protected student source' => ['event'];
        yield 'student operation receipts' => ['receipt'];
        yield 'movement source routing' => ['routing'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('damagedEvidence')]
    public function testReconciliationRejectsDamagedEvidenceWithoutChangingFinancialFacts(string $damage): void
    {
        [$s, $service, $period, $account] = $this->setupLedger();
        $this->post($s, $account->aggregateId(), EntryType::Charge, 100);
        $movement = $s->sql->rows('sis_gl_movements')[0];
        $journal = $s->journalRepository()->get(
            new \Domain\Finance\ValueObject\JournalId($movement['journal_id'])
        );
        $source = $s->sql->one('sis_history_events', ['event_id' => $journal->accounting()['source']['event_id']]);
        match ($damage) {
            'event' => $s->sql->execute(
                'UPDATE sis_history_events SET sealed_event = ? WHERE event_id = ?',
                ['fictional damaged evidence', $source['event_id']]
            ),
            'receipt' => $s->sql->execute(
                'DELETE FROM sis_history_operations WHERE stream_id = ?',
                [$source['stream_id']]
            ),
            'routing' => $s->sql->execute(
                'UPDATE sis_gl_movements SET account_id = ? WHERE id = ?',
                [EntityId::generate()->value, $movement['id']]
            ),
        };
        $before = array_map($s->sql->rows(...), ['sis_history_events', 'sis_journals', 'sis_account_balances']);
        try {
            $service->reconcile($period);
            self::fail('Damaged source evidence produced a reconciled accounting period.');
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($before, array_map(
                $s->sql->rows(...),
                ['sis_history_events', 'sis_journals', 'sis_account_balances']
            ));
        }
    }
}
