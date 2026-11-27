<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Registration\Enum\EnrollmentBasis;
use Domain\Registration\Service\CourseAuditingPolicyService;
use Domain\Registration\ValueObject\CourseAuditing;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\OfferingCreditFixture;

final class CourseAuditingTest extends TestCase
{
    private function fixture(
        bool $selfService = true,
        ?\SisFixture $sis = null,
        ?\Domain\Student\Student $student = null,
    ): array {
        require_once dirname(__DIR__) . '/Support/OfferingCreditFixture.php';
        $f = new OfferingCreditFixture(sis: $sis, fee: 10000);
        $s = $f->sis;
        $student ??= $s->student();
        $policies = new CourseAuditingPolicyService(
            new SqlCatalogRevisionRepository($s->sql),
            $s->access,
            $s->transactions,
            $s->audit
        );
        $old = $policies->current($f->section->id);
        $policies->save($f->section->id, new CourseAuditing(2500, $selfService), $old['revision']);
        $f->bind();
        $f->select($f->publish());
        return [$f, $student, $policies];
    }

    public function testReviewedAuditTakesSeatChargesAuditFeeAndRetainsZeroAcademicQuantities(): void
    {
        [$f, $student] = $this->fixture();
        $s = $f->sis;
        $selection = new RegistrationBatchSelection($f->section->id, $student->id)->withBasis(EnrollmentBasis::Audit);
        $service = $s->registrationBatches();
        $r = $service->review($selection, true);
        self::assertSame('audit', $r['basis']);
        self::assertSame([], $r['students'][0]['issues']);
        self::assertSame(2500, $r['students'][0]['charges']['total_minor']);
        $result = $service->commit(
            new EntityId($r['operation_id']),
            $selection,
            $r['revision'],
            $r['review_token'],
            true
        );
        self::assertSame('audit', $result['registrations'][0]['basis']);
        self::assertSame(1, $s->registration->sectionCount($f->section->id));
        $credit = $s->sql->rows('sis_academic_credits')[0];
        self::assertSame('audit', $credit['basis']);
        self::assertSame([0, 0, 0, null], [$credit['attempted_milli'], $credit['completed_milli'],
            $credit['grade_points_milli'], $credit['grade_id']]);
        $set = $s->inputVerifier()->verify($student->id, new \Domain\Shared\EventSourcing\HistoryReadBudget());
        self::assertSame('audit', $set->items[0]['basis']);
        $before = $s->sql->rows('sis_history_events');
        self::assertSame($result, $service->commit(
            new EntityId($r['operation_id']),
            $selection,
            $r['revision'],
            $r['review_token'],
            true
        ));
        self::assertSame($before, $s->sql->rows('sis_history_events'));
    }

    public function testUnpublishedPolicyCannotEnableAuditAndStandardEnrollmentStillWorks(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
        $s = new \SisFixture();
        $student = $s->student();
        $section = $s->section();
        try {
            $s->service->register($student->id, $section->id, true, basis: EnrollmentBasis::Audit);
            self::fail('Unpublished auditing was accepted.');
        } catch (ValidationException) {
            self::assertSame([], $s->sql->rows('sis_enrollments'));
            self::assertSame([], $s->sql->rows('sis_academic_credits'));
        }
        self::assertSame(EnrollmentBasis::Standard, $s->service->register($student->id, $section->id, true)->basis);
    }

    public function testStaffOnlyAuditPolicyBlocksSelfServiceWithoutTakingASeat(): void
    {
        [$f, $student] = $this->fixture(false);
        $r = $f->sis->registrationBatches()->review(
            new RegistrationBatchSelection($f->section->id, $student->id)->withBasis(EnrollmentBasis::Audit),
            false
        );
        self::assertNotEmpty($r['students'][0]['charges']['problems']);
        self::assertSame([], $f->sis->sql->rows('sis_enrollments'));
    }

    public function testExistingAuditCannotBeRetriedAsCreditOrGivenSelectedCredits(): void
    {
        [$f, $student] = $this->fixture();
        $s = $f->sis;
        $s->service->register($student->id, $f->section->id, true, basis: EnrollmentBasis::Audit);
        $before = $s->sql->rows('sis_history_events');
        try {
            $s->service->register($student->id, $f->section->id, true);
            self::fail('Audit enrollment was treated as credit.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
        }
        $this->expectException(ValidationException::class);
        $s->service->register($student->id, $f->section->id, true, creditsMilli: 3000, basis: EnrollmentBasis::Audit);
    }

    public function testAuditCannotReceiveAGradeAndRemainsReadableOnTheTeachingRecord(): void
    {
        [$f, $student] = $this->fixture();
        $s = $f->sis;
        $enrollment = $s->service->register($student->id, $f->section->id, true, basis: EnrollmentBasis::Audit);
        $choices = $s->academicCredits()->choices($student->id, $f->section->id, $enrollment->id);
        self::assertTrue($choices['auditing']);
        self::assertSame([], $choices['items']);
        $before = $s->sql->rows('sis_history_events');
        try {
            $s->academicCredits()->review($student->id, $f->section->id, $enrollment->id, EntityId::generate(), 0);
            self::fail('An auditor received an academic grading review.');
        } catch (ValidationException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
            self::assertNull($s->sql->rows('sis_academic_credits')[0]['grade_id']);
        }
    }

    public function testEarlyAuditDropReversesTheActualFeeAndPreservesRestrictedHistory(): void
    {
        [$f, $student] = $this->fixture();
        $s = $f->sis;
        $s->service->register($student->id, $f->section->id, true, basis: EnrollmentBasis::Audit);
        $count = count($s->sql->rows('sis_history_events'));
        $drop = $s->service->drop($student->id, $f->section->id);
        self::assertSame(EnrollmentBasis::Audit, $drop->basis);
        self::assertSame([], $s->sql->rows('sis_enrollments'));
        self::assertSame([], $s->sql->rows('sis_academic_credits'));
        self::assertGreaterThan($count, count($s->sql->rows('sis_history_events')));
        self::assertSame(0, (int) $s->sql->rows('sis_account_balances')[0]['balance_minor']);
        self::assertSame([], $s->inputVerifier()->verify(
            $student->id,
            new \Domain\Shared\EventSourcing\HistoryReadBudget()
        )->items);
    }

    public function testLateAuditDropKeepsBasisFeeAndZeroCreditWithoutAnAcademicWithdrawalGrade(): void
    {
        [$f, $student] = $this->fixture();
        $s = $f->sis;
        $s->service->register($student->id, $f->section->id, true, basis: EnrollmentBasis::Audit);
        $s->clock->time = new \DateTimeImmutable('2026-08-20T10:00:00-07:00');
        $drop = $s->service->drop($student->id, $f->section->id);
        self::assertSame(EnrollmentBasis::Audit, $drop->basis);
        $credit = $s->sql->rows('sis_academic_credits')[0];
        self::assertSame(['audit', 'W', 0, 0, 0, null], [$credit['basis'], $credit['status'],
            $credit['attempted_milli'], $credit['completed_milli'],
            $credit['grade_points_milli'], $credit['grade_id']]);
        self::assertSame(2500, (int) $s->sql->rows('sis_account_balances')[0]['balance_minor']);
        $rows = new \Infrastructure\Persistence\Repository\SqlTransferRepository($s->sql, $s->gradeCatalog())
            ->transcript($student->id);
        self::assertSame('AU/W', $rows[0]['grade_code']);
    }

    public function testPolicyEditInvalidatesUnacceptedReviewButPreservesAcceptedRetryAndFacts(): void
    {
        [$f, $student, $policies] = $this->fixture();
        $s = $f->sis;
        $selection = new RegistrationBatchSelection($f->section->id, $student->id)->withBasis(EnrollmentBasis::Audit);
        $service = $s->registrationBatches();
        $r = $service->review($selection, true);
        $result = $service->commit(
            new EntityId($r['operation_id']),
            $selection,
            $r['revision'],
            $r['review_token'],
            true
        );
        $before = $s->sql->rows('sis_history_events');
        $current = $policies->current($f->section->id);
        $policies->save($f->section->id, null, $current['revision']);
        self::assertSame($result, $service->commit(
            new EntityId($r['operation_id']),
            $selection,
            $r['revision'],
            $r['review_token'],
            true
        ));
        self::assertSame(
            EnrollmentBasis::Audit,
            $s->service->register($student->id, $f->section->id, true, basis: EnrollmentBasis::Audit)->basis
        );
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        self::assertSame(2500, (int) $s->sql->rows('sis_account_balances')[0]['balance_minor']);
    }

    public function testFailedFinalAuditRollsBackEnrollmentCreditSeatChargeAndNotice(): void
    {
        [$f, $student] = $this->fixture();
        $s = $f->sis;
        $before = array_map($s->sql->rows(...), ['sis_history_events', 'sis_history_streams',
            'sis_history_operations', 'sis_enrollments', 'sis_academic_credits', 'sis_student_accounts',
            'sis_account_entries', 'sis_account_balances', 'sis_enrollment_updates', 'sis_audit']);
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                if ($action === 'registration.created') {
                    throw new \RuntimeException('Fictional audit failure.');
                }
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        try {
            $s->courseRegistrationService($audit)->register(
                $student->id,
                $f->section->id,
                true,
                basis: EnrollmentBasis::Audit
            );
            self::fail('Required audit failure was ignored.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fictional audit failure.', $error->getMessage());
            self::assertSame($before, array_map($s->sql->rows(...), ['sis_history_events', 'sis_history_streams',
                'sis_history_operations', 'sis_enrollments', 'sis_academic_credits', 'sis_student_accounts',
                'sis_account_entries', 'sis_account_balances', 'sis_enrollment_updates', 'sis_audit']));
        }
    }

    public function testActualTranscriptRetainsAuditDesignationWithoutChangingCreditsOrGpa(): void
    {
        require_once dirname(__DIR__) . '/Support/TranscriptFixture.php';
        $t = new \TranscriptFixture();
        $request = $t->request();
        $t->postGrade($request);
        $student = $t->sis->admissions->student(new EntityId($t->repository->request($request)['student_id']));
        [$f] = $this->fixture(sis: $t->sis, student: $student);
        $s = $f->sis;
        $s->service->register($student->id, $f->section->id, true, basis: EnrollmentBasis::Audit);
        $service = $t->service();
        $r = $service->review($request);
        $audit = array_values(array_filter($r['snapshot']['credits'], static fn (array $row): bool =>
            ($row['basis'] ?? null) === 'audit'));
        self::assertCount(1, $audit);
        self::assertSame('AU', $audit[0]['grade_code']);
        self::assertFalse($audit[0]['counts_gpa']);
        self::assertSame(3000, $r['snapshot']['totals']['attempted_milli']);
        self::assertSame(3000, $r['snapshot']['totals']['completed_milli']);
        self::assertSame(4000, $r['snapshot']['totals']['gpa_milli']);
        $html = $service->preview($request, new EntityId($r['operation_id']), $r['revision'], $r['review_token']);
        self::assertStringContainsString('AU', $html);
    }

    public function testAuditIsExcludedFromDegreeMatchesGpaAttemptsAndCreditGradingQueues(): void
    {
        [$f, $student] = $this->fixture();
        $s = $f->sis;
        $s->service->register($student->id, $f->section->id, true, basis: EnrollmentBasis::Audit);
        $set = $s->inputVerifier()->verify($student->id, new \Domain\Shared\EventSourcing\HistoryReadBudget());
        self::assertSame(
            \Domain\DegreeAudit\ValueObject\CourseMatchStatus::Audited,
            new \Domain\DegreeAudit\Service\CourseMatchEvaluator()->allocationStatus(
                $set->items[0],
                ['approval_conflict' => false],
                null
            )
        );
        $sections = new \Domain\Registration\Service\RetainedSectionReader(
            \EventStoreFixture::history($s->connection->queryBuilder())
        );
        $attempts = new \Domain\DegreeAudit\Service\RetainedAttemptReader($sections)->forPolicy(
            $set,
            new \Domain\DegreeAudit\ValueObject\AuditCalculationPolicy(),
            new \Domain\Shared\EventSourcing\HistoryReadBudget()
        );
        self::assertSame([], $attempts);
        $roster = new \Infrastructure\Persistence\Repository\SqlOperationsRepository($s->sql);
        self::assertSame('audit', $roster->page('roster', $f->section->id, null)['items'][0]['basis']);
        self::assertSame([], $roster->page('roster', $f->section->id, null, gradeStatus: 'needs_grade')['items']);
        $certification = $s->gradeCertifications()->readiness($f->section->id);
        self::assertFalse($certification['ready']);
        self::assertSame([], $certification['issues']);
        self::assertSame([], $certification['rows']);
    }

    public function testRepeatingMigrationPreservesPopulatedAuditFactsAndEarlierEvents(): void
    {
        [$f, $student] = $this->fixture();
        $s = $f->sis;
        $s->service->register($student->id, $f->section->id, true, basis: EnrollmentBasis::Audit);
        $tables = ['sis_sections', 'sis_section_versions', 'sis_enrollments', 'sis_academic_credits',
            'sis_history_events', 'sis_history_streams', 'sis_history_operations'];
        $before = array_map($s->sql->rows(...), $tables);
        $migration = new \AddSisCourseAuditing('20261127000006');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($s->connection, 'test_migrations')]));
        $migration->up();
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
    }

    public function testVariableCreditAuditNeedsNoCreditChoiceAndDoesNotSatisfyACorequisite(): void
    {
        require_once dirname(__DIR__) . '/Support/OfferingCreditFixture.php';
        $f = new OfferingCreditFixture();
        $s = $f->sis;
        $student = $s->student();
        $f->range();
        $f->bind();
        $f->choose(3000, true);
        $policy = new CourseAuditingPolicyService(
            new SqlCatalogRevisionRepository($s->sql),
            $s->access,
            $s->transactions,
            $s->audit
        );
        $policy->save($f->section->id, new CourseAuditing(2500, true), $policy->current($f->section->id)['revision']);
        $f->select($f->publish());
        $s->service->register($student->id, $f->section->id, true, basis: EnrollmentBasis::Audit);
        $set = $s->inputVerifier()->verify($student->id, new \Domain\Shared\EventSourcing\HistoryReadBudget());
        self::assertSame(0, $set->items[0]['attempted_milli']);
        self::assertSame(0, $s->academicCredits()->registrationLoadFacts(
            $s->registration->enrollment($student->id, $f->section->id),
            new \Domain\Shared\EventSourcing\HistoryReadBudget()
        )['credits_milli']);
        $events = iterator_to_array(\EventStoreFixture::framework($s->connection->queryBuilder())
            ->getAggregateHistoryFor(new \Domain\AcademicRecord\ValueObject\AcademicCreditId($set->items[0]['id'])));
        $root = \Domain\AcademicRecord\AcademicCredit::reconstituteFromEventStream(
            new \Codefy\Domain\EventSourcing\EventStream($events[0]->aggregateId(), $events)
        );
        self::assertFalse($root->canDecideEarnedCeus());
        self::assertFalse($root->canCorrectRegisteredCredits());
        $decision = $s->corequisites()->evaluate(
            $student->id,
            $s->workPursuits()->activeSelection($student->id)->id,
            $s->term,
            [$f->section->courseId->value],
            new \Domain\Shared\EventSourcing\HistoryReadBudget()
        );
        self::assertFalse($decision->isMet());
    }

    public function testChangingAuditBasisProjectionCannotTurnItIntoQualifyingAcademicWork(): void
    {
        [$f, $student] = $this->fixture();
        $s = $f->sis;
        $enrollment = $s->service->register($student->id, $f->section->id, true, basis: EnrollmentBasis::Audit);
        $before = $s->sql->rows('sis_history_events');
        $s->sql->update('sis_enrollments', ['basis' => 'standard'], ['id' => $enrollment->id->value]);
        try {
            $s->inputVerifier()->verify($student->id, new \Domain\Shared\EventSourcing\HistoryReadBudget());
            self::fail('A damaged enrollment basis was accepted.');
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
        }
    }

    public function testCreditReviewCannotBeConfirmedAsAuditAndStaleAuditPolicyNeedsNewReview(): void
    {
        [$f, $student, $policies] = $this->fixture();
        $s = $f->sis;
        $normal = new RegistrationBatchSelection($f->section->id, $student->id);
        $audit = $normal->withBasis(EnrollmentBasis::Audit);
        $service = $s->registrationBatches();
        $creditReview = $service->review($normal, true);
        try {
            $service->commit(
                new EntityId($creditReview['operation_id']),
                $audit,
                $creditReview['revision'],
                $creditReview['review_token'],
                true
            );
            self::fail('A credit review authorized an audit registration.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $s->sql->rows('sis_enrollments'));
        }
        $r = $service->review($audit, true);
        $current = $policies->current($f->section->id);
        $policies->save($f->section->id, new CourseAuditing(9000, true), $current['revision']);
        $this->expectException(ConcurrentModificationException::class);
        $service->commit(new EntityId($r['operation_id']), $audit, $r['revision'], $r['review_token'], true);
    }

    public function testReviewedRestorationKeepsAuditBasisZeroCreditAndOriginalCharge(): void
    {
        [$f, $student] = $this->fixture();
        $s = $f->sis;
        $enrollment = $s->service->register($student->id, $f->section->id, true, basis: EnrollmentBasis::Audit);
        $s->clock->time = new \DateTimeImmutable('2026-08-20T10:00:00-07:00');
        $s->service->drop($student->id, $f->section->id);
        $money = $s->sql->rows('sis_account_entries');
        $service = $s->reinstatements();
        $r = $service->review($student->id, $f->section->id, $enrollment->id, 'Fictional audit withdrawal correction.');
        self::assertTrue($r['can_confirm']);
        $service->confirm(
            $student->id,
            $f->section->id,
            $enrollment->id,
            new EntityId($r['operation_id']),
            $r['revision'],
            $r['reason'],
            $r['review_token']
        );
        self::assertSame(EnrollmentBasis::Audit, $s->registration->enrollment($student->id, $f->section->id)->basis);
        self::assertSame(0, $s->sql->rows('sis_academic_credits')[0]['attempted_milli']);
        self::assertSame($money, $s->sql->rows('sis_account_entries'));
        self::assertSame('audit', $s->inputVerifier()->verify(
            $student->id,
            new \Domain\Shared\EventSourcing\HistoryReadBudget()
        )->items[0]['basis']);
    }

    public function testAuditUsesZeroRegisteredCreditTuitionAndKeepsApplicableTermFees(): void
    {
        [$f, $student] = $this->fixture();
        $s = $f->sis;
        $db = $s->connection->queryBuilder();
        $rules = new \Domain\Finance\Service\TermBillingService(
            new \Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository(
                $s->sql,
                \EventStoreFixture::framework($db),
                $s->transactions,
                \EventStoreFixture::financialWriter($db)
            ),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->financialCommands()
        );
        foreach (
            [\Domain\Finance\Enum\BillingBasis::RegisteredCredit,
            \Domain\Finance\Enum\BillingBasis::Term] as $basis
        ) {
            $rules->define(
                EntityId::generate(),
                $s->term,
                null,
                'Fictional audit pricing',
                'tuition',
                new \Domain\Finance\ValueObject\Money(500, 'USD'),
                'Fictional institutional fees.',
                $basis
            );
        }
        $selection = new RegistrationBatchSelection($f->section->id, $student->id)->withBasis(EnrollmentBasis::Audit);
        $service = $s->registrationBatches();
        $r = $service->review($selection, true);
        self::assertSame(3000, $r['students'][0]['charges']['total_minor']);
        $service->commit(new EntityId($r['operation_id']), $selection, $r['revision'], $r['review_token'], true);
        self::assertSame(3000, (int) $s->sql->rows('sis_account_balances')[0]['balance_minor']);
        self::assertCount(2, $s->sql->rows('sis_account_entries'));
    }

    public function testAuditPolicyDriftAtTheFinalBoundaryRollsBackEveryAcceptedEffect(): void
    {
        [$f, $student] = $this->fixture();
        $s = $f->sis;
        $tables = ['sis_sections', 'sis_enrollments', 'sis_academic_credits', 'sis_history_events',
            'sis_history_streams', 'sis_history_operations', 'sis_student_accounts', 'sis_account_entries',
            'sis_account_balances', 'sis_enrollment_updates', 'sis_audit'];
        $before = array_map($s->sql->rows(...), $tables);
        $audit = new class ($s, $f->section->id) implements \Domain\Audit\Repository\AuditTrail {
            public function __construct(private \SisFixture $sis, private EntityId $section)
            {
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                $this->sis->audit->record($actor, $action, $subject);
                if ($action === 'registration.created') {
                    $this->sis->sql->update('sis_sections', ['auditing' => json_encode([
                        'fee_minor' => 9999, 'self_service' => true,
                    ], JSON_THROW_ON_ERROR)], ['id' => $this->section->value]);
                }
            }
            public function forSubject(EntityId $subject): array
            {
                return $this->sis->audit->forSubject($subject);
            }
        };
        try {
            $s->courseRegistrationService($audit)->register(
                $student->id,
                $f->section->id,
                true,
                basis: EnrollmentBasis::Audit
            );
            self::fail('Changed audit policy left a committed enrollment.');
        } catch (\DomainException | ConcurrentModificationException) {
            self::assertSame($before, array_map($s->sql->rows(...), $tables));
        }
    }
}
