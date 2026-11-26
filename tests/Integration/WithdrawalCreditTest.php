<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Finance\Enum\EntryType;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Gradebook\GradeDefinition;
use Domain\Registration\ValueObject\DropWindows;
use Domain\Registration\ValueObject\RegistrationPlanSelection;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class WithdrawalCreditTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function fixture(int $rate = 5000, int $fee = 10001, bool $tracked = false): array
    {
        $s = new SisFixture();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'W', 0, false, false));
        $s->publishDropPolicy($s->term, -1, new DropWindows([
            ['through' => '2026-09-10', 'grade_code' => 'W', 'credit_basis_points' => $rate],
            ['through' => null, 'grade_code' => 'W', 'credit_basis_points' => 0],
        ]));
        $student = $s->student()->id;
        $section = $s->section(fee: $fee)->id;
        if ($tracked) {
            $offering = $s->registration->section($section);
            $version = $s->publishCourse($offering->courseId);
            $bindings = new \Domain\Registration\Service\SectionCourseBindingService(
                new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
                $s->coursePublications(),
                new \Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository($s->sql),
                \EventStoreFixture::history($s->connection->queryBuilder()),
                $s->access,
                $s->transactions,
                $s->audit,
                $s->dropCipher,
                $s->clock,
            );
            $binding = $bindings->review($section, $version, 'Fictional original course definition.');
            $bindings->commit($section, $binding['review_token']);
        }
        $enrollment = $s->service->register($student, $section, true)->id;
        $row = $s->sql->rows('sis_student_accounts', ['student_id' => $student->value], 1)[0] ?? null;
        $account = $row === null ? null : new AccountId($row['id']);
        $s->clock->time = new DateTimeImmutable('2026-09-02T12:00:00-07:00');
        return [$s, $student, $section, $enrollment, $account];
    }

    private function confirm(SisFixture $s, array $review, ?AuditTrail $audit = null): array
    {
        return $s->dropService(audit: $audit)->commit(
            new EntityId($review['student_id']),
            new EntityId($review['section_id']),
            new EntityId($review['enrollment_id']),
            $review['version'],
            new EntityId($review['operation_id']),
            $review['revision'],
            $review['effective_on'],
        );
    }

    public function testReviewedWithdrawalPostsAnExactAccountCreditAndPreservesItsOriginalReceipt(): void
    {
        [$s, $student, $section, $enrollment, $account] = $this->fixture();
        $review = $s->drops->review($student, $section);
        self::assertSame(
            ['basis_points' => 5000, 'minor' => 5001, 'currency' => 'USD', 'charge_count' => 1],
            $review['withdrawal_credit']
        );
        self::assertSame(10001, $s->accounts->get($account)->balance()->minor);
        $receipt = $this->confirm($s, $review);
        self::assertSame(5000, $s->accounts->get($account)->balance()->minor);
        self::assertFalse($receipt['cash_refund_issued']);
        self::assertSame('kept', $receipt['course_charge']);
        self::assertSame('W', $s->sql->one('sis_enrollments', ['id' => $enrollment->value])['status']);
        $before = $s->financialEvents();
        self::assertSame($receipt, $this->confirm($s, $review));
        self::assertSame($before, $s->financialEvents());
    }

    public function testTwoCoursesInTheSameAccountCanBeCreditedAtomically(): void
    {
        [$s, $student, $section, $enrollment, $account] = $this->fixture();
        $s->clock->time = new DateTimeImmutable('2026-08-10T12:00:00-07:00');
        $other = $s->section(fee: 20000)->id;
        $s->service->register($student, $other, true);
        $s->clock->time = new DateTimeImmutable('2026-09-02T12:00:00-07:00');
        $selection = new RegistrationPlanSelection($student, $section, $other);
        $review = $s->drops->reviewPlan($selection, true);
        self::assertSame([5001, 10000], array_column(array_column($review['courses'], 'withdrawal_credit'), 'minor'));
        $s->drops->commitPlan(
            $selection,
            new EntityId($review['operation_id']),
            $review['revision'],
            $review['review_token'],
            true
        );
        self::assertSame(15000, $s->accounts->get($account)->balance()->minor);
    }

    private function manualCredit(SisFixture $s, AccountId $id, EntityId $enrollment, int $minor): void
    {
        $account = $s->accounts->get($id);
        $account->creditCharge(
            $enrollment,
            EntityId::generate(),
            new Money($minor, 'USD'),
            $s->access->actor,
            hash('sha256', 'Fictional approved earlier credit.')
        );
        $s->saveAccount($account);
    }

    public function testFinalGroupedAuditCannotChangeAnEarlierCoursesCreditedCharges(): void
    {
        [$s, $student, $section, $enrollment, $id] = $this->fixture();
        $s->clock->time = new DateTimeImmutable('2026-08-10T12:00:00-07:00');
        $other = $s->section(fee: 20000)->id;
        $s->service->register($student, $other, true);
        $s->clock->time = new DateTimeImmutable('2026-09-02T12:00:00-07:00');
        $selection = new RegistrationPlanSelection($student, $section, $other);
        $review = $s->drops->reviewPlan($selection, true);
        $before = hash('sha256', serialize($s->sql->rows('sis_history_events')));
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(function ($actor, $action) use ($s, $id, $enrollment): void {
            if ($action === 'registration.drop_plan_completed') {
                $this->manualCredit($s, $id, $enrollment, 1000);
            }
        });
        try {
            $s->dropService(audit: $audit)->commitPlan(
                $selection,
                new EntityId($review['operation_id']),
                $review['revision'],
                $review['review_token'],
                true
            );
            self::fail('An earlier course changed at the final group boundary.');
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($before, hash('sha256', serialize($s->sql->rows('sis_history_events'))));
            self::assertSame(30001, $s->accounts->get($id)->balance()->minor);
            self::assertSame([], $s->sql->rows('sis_course_drop_receipts'));
            self::assertSame('A', $s->registration->enrollment($student, $section)->status->value);
        }
    }

    public function testEarlierCreditsCountTowardTheTargetAndLargerApprovedCreditsArePreserved(): void
    {
        foreach ([2000 => 3001, 7000 => 0] as $prior => $additional) {
            [$s, $student, $section, $enrollment, $id] = $this->fixture();
            $this->manualCredit($s, $id, $enrollment, $prior);
            $review = $s->drops->review($student, $section);
            self::assertSame($additional, $review['withdrawal_credit']['minor']);
            $this->confirm($s, $review);
            self::assertSame(10001 - $prior - $additional, $s->accounts->get($id)->balance()->minor);
        }
    }

    public function testZeroAndFullPercentPeriodsAndFreeCoursesDoNotInventMoney(): void
    {
        foreach ([[0, 10001, 0], [10000, 10001, 10001], [5000, 0, 0]] as [$rate, $fee, $expected]) {
            [$s, $student, $section, , $id] = $this->fixture($rate, $fee);
            $review = $s->drops->review($student, $section);
            self::assertSame($expected, $review['withdrawal_credit']['minor'] ?? 0);
            $this->confirm($s, $review);
            self::assertSame($fee - $expected, $id === null ? 0 : $s->accounts->get($id)->balance()->minor);
            if ($id === null) {
                self::assertSame([], $s->sql->rows('sis_student_accounts'));
            }
        }
    }

    public function testRelevantChargeChangesInvalidateTheReviewButUnrelatedChargesDoNot(): void
    {
        [$s, $student, $section, $enrollment, $id] = $this->fixture();
        $review = $s->drops->review($student, $section);
        $this->manualCredit($s, $id, $enrollment, 1000);
        try {
            $this->confirm($s, $review);
            self::fail('A changed charge was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame('A', $s->registration->enrollment($student, $section)->status->value);
            self::assertSame([], $s->sql->rows('sis_course_drop_receipts'));
        }
        $review = $s->drops->review($student, $section);
        $account = $s->accounts->get($id);
        $account->post(EntityId::generate(), EntryType::Charge, new Money(2000, 'USD'), $s->access->actor);
        $s->saveAccount($account);
        $receipt = $this->confirm($s, $review);
        self::assertSame(4001, $receipt['withdrawal_credit']['minor']);
        self::assertSame(7000, $s->accounts->get($id)->balance()->minor);
    }

    public function testAuditFailureOrCompletionBoundarySourceChangeRollsBackTheWholeWithdrawal(): void
    {
        foreach (['audit', 'charge', 'policy', 'authority'] as $change) {
            [$s, $student, $section, $enrollment, $id] = $this->fixture();
            $review = $s->drops->review($student, $section);
            $tables = ['sis_history_events', 'sis_history_streams', 'sis_history_operations',
                'sis_enrollments', 'sis_academic_credits', 'sis_account_entries', 'sis_account_balances',
                'sis_course_drop_receipts', 'sis_audit'];
            $before = hash('sha256', serialize(array_map($s->sql->rows(...), $tables)));
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(function () use ($s, $change, $id, $enrollment): void {
                if ($change === 'audit') {
                    throw new \RuntimeException('Fictional interrupted audit.');
                }
                if ($change === 'charge') {
                    $this->manualCredit($s, $id, $enrollment, 1000);
                } elseif ($change === 'policy') {
                    $s->publishDropPolicy($s->term, 0, new DropWindows([
                        ['through' => null, 'grade_code' => 'W', 'credit_basis_points' => 0],
                    ]));
                } else {
                    $s->access->staff = false;
                    $s->access->actor = EntityId::generate();
                }
            });
            try {
                $this->confirm($s, $review, $audit);
                self::fail('An interrupted withdrawal succeeded: ' . $change);
            } catch (\RuntimeException | \DomainException $e) {
                self::assertSame($before, hash('sha256', serialize(array_map($s->sql->rows(...), $tables))), $change);
            }
        }
    }

    public function testReinstatementRequiresResolutionOfTheWithdrawalCreditAndKeepsTheOriginalReceipt(): void
    {
        [$s, $student, $section, $enrollment, $id] = $this->fixture(tracked: true);
        $drop = $s->drops->review($student, $section);
        $receipt = $this->confirm($s, $drop);
        try {
            $s->reinstatements()->review($student, $section, $enrollment, 'Fictional withdrawal correction.');
            self::fail('An active withdrawal credit was retained on reinstatement.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Finance', json_encode($e->errors, JSON_THROW_ON_ERROR));
        }
        $account = $s->accounts->get($id);
        $credit = $s->sql->rows('sis_account_entries', ['type' => EntryType::ChargeCredit->value])[0];
        $s->finance()->reverse(
            $id,
            new EntityId($credit['operation_id']),
            $account->playhead(),
            'Fictional finance resolution before reinstatement.'
        );
        $review = $s->reinstatements()->review($student, $section, $enrollment, 'Fictional withdrawal correction.');
        $s->reinstatements()->confirm(
            $student,
            $section,
            $enrollment,
            new EntityId($review['operation_id']),
            $review['revision'],
            $review['reason'],
            $review['review_token']
        );
        self::assertSame(10001, $s->accounts->get($id)->balance()->minor);
        self::assertSame($receipt, $this->confirm($s, $drop));
    }

    public function testReviewDoesNotExposeWholeAccountOrChargeIdentitiesAndOwnerCanUseApprovedSchedule(): void
    {
        [$s, $student, $section, , $id] = $this->fixture();
        $person = $s->admissions->person($s->admissions->student($student)->personId);
        $s->access->actor = $person->ownerUserId;
        $s->access->staff = false;
        $review = $s->drops->review($student, $section);
        $json = json_encode($review, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString((string) $id, $json);
        self::assertStringNotContainsString('balance_minor', $json);
        self::assertSame(
            ['basis_points', 'minor', 'currency', 'charge_count'],
            array_keys($review['withdrawal_credit']),
        );
        self::assertSame(5001, $this->confirm($s, $review)['withdrawal_credit']['minor']);
    }

    public function testWithdrawalEvidenceMustMatchItsActualOriginalChargeOnReplay(): void
    {
        [$s, $student, $section, $enrollment, $id] = $this->fixture();
        $review = $s->drops->review($student, $section);
        $basis = new \Domain\Finance\ValueObject\WithdrawalCreditBasis([
            'enrollment_id' => $enrollment->value, 'drop_operation_id' => $review['operation_id'],
            'policy_reference' => $review['outcome']['policy_reference'], 'basis_points' => 5000,
            'original_minor' => 10002, 'credited_before_minor' => 0, 'effective_on' => $review['effective_on'],
        ]);
        $account = $s->accounts->get($id);
        $before = $s->financialEvents();
        try {
            $account->creditCharge(
                $enrollment,
                EntityId::generate(),
                new Money(5001, 'USD'),
                $s->access->actor,
                hash('sha256', 'Fictional invalid evidence.'),
                $basis
            );
            self::fail('An invented original amount was accepted.');
        } catch (\DomainException) {
            self::assertSame($before, $s->financialEvents());
            self::assertSame(10001, $s->accounts->get($id)->balance()->minor);
        }
    }

    public function testAnUnpostableCreditIsRejectedDuringReviewWithoutLeakingTheAccountBalance(): void
    {
        [$s, $student, $section, , $id] = $this->fixture();
        $account = $s->accounts->get($id);
        $account->post(EntityId::generate(), EntryType::Payment, new Money(Money::MAX_MINOR, 'USD'), $s->access->actor);
        $account->post(EntityId::generate(), EntryType::Payment, new Money(10001, 'USD'), $s->access->actor);
        $s->saveAccount($account);
        $before = count($s->financialEvents());
        try {
            $s->drops->review($student, $section);
            self::fail('An unpostable credit produced a review.');
        } catch (ValidationException $e) {
            self::assertSame('withdrawal_credit', $e->errors[0]['field']);
            self::assertStringNotContainsString((string) -Money::MAX_MINOR, json_encode($e->errors));
            self::assertCount($before, $s->financialEvents());
        }
    }

    public function testGuidedHiatusReviewsAndAppliesTheSameCreditAndPreservesItsOriginalResult(): void
    {
        require_once dirname(__DIR__) . '/Support/HiatusFixture.php';
        [$s, $student, , , $id] = $this->fixture();
        $h = new \HiatusFixture($s);
        $service = $h->workflow();
        $dates = new \Domain\Shared\ValueObject\DateRange(new DateTimeImmutable('2026-09-01'));
        $review = $service->review($student, \Domain\Student\Enum\HiatusCode::Withdrawal, $dates);
        self::assertSame(5001, $review['courses'][0]['withdrawal_credit']['minor']);
        $hiatus = new \Domain\Student\StudentHiatus(
            new EntityId($review['operation_id']),
            $student,
            \Domain\Student\Enum\HiatusCode::Withdrawal,
            $dates
        );
        $impact = new \Domain\Student\ValueObject\HiatusImpact(
            true,
            $review['revision'],
            [],
            new EntityId($review['courses'][0]['section_id'])
        );
        $result = $service->commit($hiatus, 'Fictional guided withdrawal.', $impact, $review['review_token']);
        self::assertSame(5000, $s->accounts->get($id)->balance()->minor);
        self::assertFalse($result['impact']['automatic_refund']);
        self::assertSame($result, $service->commit(
            $hiatus,
            'Fictional guided withdrawal.',
            $impact,
            $review['review_token']
        ));
    }

    public function testGuidedHiatusRejectsStaleCreditAmountsAndRollsBackFinalFinancialChanges(): void
    {
        require_once dirname(__DIR__) . '/Support/HiatusFixture.php';
        foreach (['stale', 'completion'] as $change) {
            [$s, $student, $section, $enrollment, $id] = $this->fixture();
            $h = new \HiatusFixture($s);
            $dates = new \Domain\Shared\ValueObject\DateRange(new DateTimeImmutable('2026-09-01'));
            $review = $h->workflow()->review($student, \Domain\Student\Enum\HiatusCode::Withdrawal, $dates);
            $hiatus = new \Domain\Student\StudentHiatus(
                new EntityId($review['operation_id']),
                $student,
                \Domain\Student\Enum\HiatusCode::Withdrawal,
                $dates
            );
            $impact = new \Domain\Student\ValueObject\HiatusImpact(true, $review['revision'], [], $section);
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(function ($actor, $action) use ($s, $id, $enrollment): void {
                if ($action === 'student.hiatus_created') {
                    $this->manualCredit($s, $id, $enrollment, 1000);
                }
            });
            if ($change === 'stale') {
                $this->manualCredit($s, $id, $enrollment, 1000);
            }
            $before = hash('sha256', serialize($s->sql->rows('sis_history_events')));
            try {
                $h->workflow(audit: $audit)->commit(
                    $hiatus,
                    'Fictional interrupted plan.',
                    $impact,
                    $review['review_token']
                );
                self::fail('A changed hiatus credit was accepted.');
            } catch (ConcurrentModificationException | \Domain\Shared\EventSourcing\CorruptHistoryException) {
                self::assertSame($before, hash('sha256', serialize($s->sql->rows('sis_history_events'))));
                self::assertSame([], $s->sql->rows('sis_student_hiatuses'));
                self::assertSame([], $s->sql->rows('sis_course_drop_receipts'));
                self::assertTrue($s->admissions->student($student)->active);
                self::assertSame('A', $s->registration->enrollment($student, $section)->status->value);
                self::assertSame($change === 'stale' ? 9001 : 10001, $s->accounts->get($id)->balance()->minor);
            }
        }
    }
}
