<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\OfferingCreditFixture;

final class StudentSelectedCreditsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/OfferingCreditFixture.php';
    }

    public function testTwoStudentsKeepDifferentExactAcademicQuantitiesAndOriginalRetries(): void
    {
        $f = $this->offering();
        $s = $f->sis;
        $first = $s->student();
        $second = $s->student();
        $a = $s->service->register($first->id, $f->section->id, creditsMilli: 1500);
        $s->service->register($second->id, $f->section->id, creditsMilli: 4500);
        self::assertSame(1500, $s->enrollmentLedger()->summary($a->id)["selected_credits_milli"]);
        self::assertSame(
            1500,
            (int) $s->sql->one(
                'sis_academic_credits',
                ['student_id' => $first->id->value]
            )['attempted_milli']
        );
        self::assertSame(
            4500,
            (int) $s->sql->one(
                'sis_academic_credits',
                ['student_id' => $second->id->value]
            )['attempted_milli']
        );
        self::assertSame(
            $a->id->value,
            $s->service->register(
                $first->id,
                $f->section->id,
                creditsMilli: 1500
            )->id->value
        );
        $this->expectException(ValidationException::class);
        $s->service->register($first->id, $f->section->id, creditsMilli: 2500);
    }

    public function testMissingAndDisallowedChoicesWriteNoRegistrationFacts(): void
    {
        $f = $this->offering();
        $s = $f->sis;
        $student = $s->student();
        $before = [$s->sql->rows('sis_history_events'), $s->sql->rows('sis_academic_credits')];
        foreach ([null, 500, 1250, 6500] as $quantity) {
            try {
                $s->service->register($student->id, $f->section->id, creditsMilli: $quantity);
                self::fail('An unchosen or disallowed quantity was accepted.');
            } catch (ValidationException) {
                self::assertSame($before, [$s->sql->rows('sis_history_events'), $s->sql->rows('sis_academic_credits')]);
            }
        }
    }

    public function testGroupReviewPricesEachStudentsChoiceAndBindsItToConfirmation(): void
    {
        $f = $this->offering();
        $s = $f->sis;
        $a = $s->student()->id;
        $b = $s->student()->id;
        $this->prices($s);
        $group = new \Domain\Registration\ValueObject\RegistrationBatchSelection($f->section->id, $a, $b);
        $batch = $s->registrationBatches();
        $missing = $batch->review($group);
        self::assertNotEmpty($missing['students'][0]['charges']['problems']);
        $group = $group->withCreditChoices([$a->value => 1500, $b->value => 4500]);
        $review = $batch->review($group);
        self::assertSame([1500, 4500], array_column($review['students'], 'credits_milli'));
        self::assertSame([15000, 45000], array_column(array_column($review['students'], 'charges'), 'total_minor'));
        $result = $batch->commit(
            new \Domain\Shared\ValueObject\EntityId($review['operation_id']),
            $group,
            $review['revision'],
            $review['review_token']
        );
        self::assertSame(2, $result['created']);
        self::assertSame(
            [1500,
            4500],
            array_map(
                'intval',
                array_column(
                    $s->sql->rows('sis_academic_credits'),
                    'attempted_milli'
                )
            )
        );
        self::assertSame($result, $batch->commit(
            new \Domain\Shared\ValueObject\EntityId($review['operation_id']),
            $group,
            $review['revision'],
            $review['review_token']
        ));
        $this->expectException(\Domain\Shared\Exception\ConcurrentModificationException::class);
        $batch->commit(
            new \Domain\Shared\ValueObject\EntityId($review['operation_id']),
            $group->withCreditChoices([$a->value => 2500, $b->value => 4500]),
            $review['revision'],
            $review['review_token']
        );
    }

    public function testScheduleSharesExactChoiceAcrossFeesLoadAndAcademicRecords(): void
    {
        $f = $this->offering();
        $s = $f->sis;
        $other = $s->section();
        $student = $s->student()->id;
        $this->prices($s);
        $plan = new \Domain\Registration\ValueObject\RegistrationPlanSelection($student, $f->section->id, $other->id)
            ->withCreditChoices([$f->section->id->value => 2500]);
        $batch = $s->registrationBatches();
        $review = $batch->reviewPlan($plan, true);
        self::assertTrue($review['can_confirm']);
        $result = $batch->commitPlan(
            new \Domain\Shared\ValueObject\EntityId($review['operation_id']),
            $plan,
            $review['revision'],
            $review['review_token'],
            true
        );
        self::assertSame(2, $result['created']);
        $units = array_map('intval', array_column($s->sql->rows('sis_academic_credits'), 'attempted_milli'));
        sort($units);
        self::assertSame([2500, 3000], $units);
        $pursuit = new \Domain\Shared\ValueObject\EntityId($s->sql->one(
            'sis_student_programs',
            ['student_id' => $student->value]
        )['id']);
        self::assertSame(5500, $s->enrollmentLoad()->review($student, $pursuit, $s->term, 'staff')['credits_milli']);
    }

    public function testWaitlistRetainsRequestedQuantityForLaterReviewedAllocation(): void
    {
        $f = $this->offering();
        $s = $f->sis;
        $waitlists = $s->waitlists();
        $policy = $waitlists->reviewPolicy(
            $f->section->id,
            new \Domain\Registration\Waitlist\WaitlistPolicy(true, 50)->describe(),
            0,
            'Fictional variable-credit waiting policy.'
        );
        $waitlists->confirmPolicy(
            $f->section->id,
            new \Domain\Shared\ValueObject\EntityId($policy['operation_id']),
            $policy['policy'],
            $policy['version'],
            $policy['reason'],
            $policy['review_token']
        );
        $s->sql->update('sis_sections', ['capacity' => 1], ['id' => $f->section->id->value]);
        // Capacity is part of the selected offering: publish the deliberate change before using it.
        $f->amend($f->publish());
        $filler = $s->student()->id;
        $waiting = $s->student()->id;
        $s->service->register($filler, $f->section->id, true, creditsMilli: 3000);
        $f->choose(2500, true);
        try {
            $waitlists->review($waiting, $f->section->id, 'requested', true, null, creditsMilli: 1500);
            self::fail('An unpublished offering credit edit accepted a waiting request.');
        } catch (ValidationException $error) {
            self::assertSame('credits_milli', $error->errors[0]['field']);
        }
        $f->choose(3000, true);
        $review = $waitlists->review($waiting, $f->section->id, 'requested', true, null, creditsMilli: 1500);
        $operation = new \Domain\Shared\ValueObject\EntityId($review['operation_id']);
        $receipt = $waitlists->confirm(
            $waiting,
            $f->section->id,
            $operation,
            'requested',
            true,
            $review['revision'],
            $review['review_token'],
            creditsMilli: 1500
        );
        self::assertSame(1500, $waitlists->view($waiting, $f->section->id, true)['credits_milli']);
        $actor = $s->access->actor;
        $s->access->actor = $s->admissions->person($s->admissions->student($waiting)->personId)->ownerUserId;
        self::assertCount(1, $waitlists->mine($waiting)['items']);
        $s->access->actor = $actor;
        $check = new \Infrastructure\Projection\SqlWaitlistProjectionVerifier(
            $s->sql,
            new \Infrastructure\Persistence\Waitlist\SqlWaitlistRepository($s->sql, $s->dropCipher),
            $s->waitlistLedger()
        )->verify($operation);
        self::assertSame('verified', $check->status);
        self::assertSame($receipt, $waitlists->confirm(
            $waiting,
            $f->section->id,
            $operation,
            'requested',
            true,
            $review['revision'],
            $review['review_token'],
            creditsMilli: 1500
        ));
        $drop = $s->drops->review($filler, $f->section->id);
        $s->drops->commit(
            $filler,
            $f->section->id,
            new \Domain\Shared\ValueObject\EntityId($drop['enrollment_id']),
            $drop['version'],
            new \Domain\Shared\ValueObject\EntityId($drop['operation_id']),
            $drop['revision'],
            $drop['effective_on']
        );
        $batch = $s->registrationBatches();
        $group = new \Domain\Registration\ValueObject\RegistrationBatchSelection($f->section->id, $waiting);
        $review = $batch->review($group);
        self::assertSame(1500, $review['students'][0]['credits_milli']);
        $batch->commit(
            new \Domain\Shared\ValueObject\EntityId($review['operation_id']),
            $group,
            $review['revision'],
            $review['review_token']
        );
        self::assertSame(
            1500,
            (int) $s->sql->one(
                'sis_academic_credits',
                ['student_id' => $waiting->value]
            )['attempted_milli']
        );
        self::assertNull($waitlists->view($waiting, $f->section->id, true)['entry_id']);
    }

    private function prices(\SisFixture $s): void
    {
        $db = $s->connection->queryBuilder();
        new \Domain\Finance\Service\TermBillingService(
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
        )->define(
            \Domain\Shared\ValueObject\EntityId::generate(),
            $s->term,
            null,
            'Fictional exact credit price',
            'tuition',
            new \Domain\Finance\ValueObject\Money(10000, 'USD'),
            'Fictional approved credit pricing.',
            \Domain\Finance\Enum\BillingBasis::RegisteredCredit
        );
    }

    public function testSectionChangeUsesANewTargetChoiceAndRetainsTheOriginalReceipt(): void
    {
        $from = $this->offering();
        $s = $from->sis;
        $to = new OfferingCreditFixture($s);
        $s->sql->update(
            'sis_sections',
            ['course_id' => $from->section->courseId->value],
            ['id' => $to->section->id->value]
        );
        $to->section = $s->registration->section($to->section->id);
        $to->bind();
        $to->choose(3000, true);
        $to->select($to->publish());
        $this->prices($s);
        $student = $s->student()->id;
        $s->service->register($student, $from->section->id, true, creditsMilli: 1500);
        $selection = new \Domain\Registration\ValueObject\SectionChangeSelection(
            $student,
            $from->section->id,
            $to->section->id,
            4500
        );
        $service = $s->sectionChanges();
        $review = $service->review($selection, true);
        self::assertTrue($review['can_confirm']);
        self::assertSame(15000, $review['financial']['credit_minor']);
        self::assertSame(45000, $review['financial']['new_charge_minor']);
        $operation = new \Domain\Shared\ValueObject\EntityId($review['operation_id']);
        $result = $service->commit($operation, $selection, $review['revision'], $review['review_token'], true);
        self::assertSame(4500, (int) $s->sql->rows('sis_academic_credits')[0]['attempted_milli']);
        self::assertSame(
            $result,
            $service->commit(
                $operation,
                $selection,
                $review['revision'],
                $review['review_token'],
                true
            )
        );
    }

    public function testFutureOfferingChangesKeepOriginalSelectedCreditsAndDetectProjectionDamage(): void
    {
        $f = $this->offering();
        $s = $f->sis;
        $student = $s->student()->id;
        $enrollment = $s->service->register($student, $f->section->id, true, creditsMilli: 1500);
        $f->choose(4500, false);
        $f->amend($f->publish());
        $s->service->register($student, $f->section->id, true, creditsMilli: 1500);
        $campus = new \Infrastructure\Persistence\Repository\SqlCampusRepository(
            $s->sql,
            $s->clock,
            new \Infrastructure\Portal\SymfonyContentMarkup()
        );
        self::assertSame(1500, (int) $campus->enrollments($student)[0]['credits_milli']);
        $s->sql->update(
            'sis_enrollment_section_versions',
            ['selected_credits_milli' => 2500],
            ['id' => $enrollment->id->value]
        );
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $s->service->register($student, $f->section->id, true, creditsMilli: 1500);
    }

    public function testFinalAuditPolicyChangeRollsBackAcademicFinancialAndRegistrationFacts(): void
    {
        $f = $this->offering();
        $s = $f->sis;
        $this->prices($s);
        $student = $s->student()->id;
        $tables = ['sis_sections', 'sis_enrollments', 'sis_academic_credits', 'sis_history_events',
            'sis_history_streams', 'sis_history_operations', 'sis_account_balances', 'sis_audit'];
        $before = array_map($s->sql->rows(...), $tables);
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($s, $f): void {
            $s->sql->update('sis_sections', ['student_selectable_credits' => 0], ['id' => $f->section->id->value]);
        });
        try {
            $s->courseRegistrationService($audit)->register($student, $f->section->id, true, creditsMilli: 1500);
            self::fail('Changed choice rules committed.');
        } catch (\DomainException) {
            self::assertSame($before, array_map($s->sql->rows(...), $tables));
        }
    }

    public function testFixedAndUnboundCoursesCannotEnableIndividualStudentChoice(): void
    {
        $f = new OfferingCreditFixture();
        foreach ([false, true] as $range) {
            if ($range) {
                $f->range();
            }
            try {
                $f->choose(3000, true);
                self::fail('An unbound or fixed course enabled individual credit choices.');
            } catch (ValidationException $error) {
                self::assertSame('student_selectable', $error->errors[0]['field']);
            }
        }
    }

    public function testAnAwardedCourseReplacementKeepsTheOriginalRegistrationChoice(): void
    {
        $f = $this->offering();
        $s = $f->sis;
        $s->gradeScale([['code' => 'A', 'min_score_milli' => 0, 'max_score_milli' => 100000,
            'points_milli' => 4000, 'passes' => true, 'counts_gpa' => true]]);
        $student = $s->student()->id;
        $enrollment = $s->service->register($student, $f->section->id, true, creditsMilli: 1500);
        $academic = $s->academicCredits();
        $grade = new \Domain\Shared\ValueObject\EntityId(
            $academic->choices($student, $f->section->id, $enrollment->id)['items'][0]['id']
        );
        $gradeReview = $academic->review($student, $f->section->id, $enrollment->id, $grade, 0);
        $academic->commit($student, $f->section->id, $gradeReview['review_token']);
        $credit = new \Domain\Shared\ValueObject\EntityId(
            $s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value])['id']
        );
        $target = $s->section()->courseId;
        $version = $s->publishCourse($target);
        $review = $s->academicCourses()->review(
            $student,
            $f->section->id,
            $enrollment->id,
            $version,
            $academic->auditFacts($credit, $student)['credit']['version'],
            '2026-08-10',
            'Fictional evidence-supported awarded course replacement.',
            $target
        );
        $s->academicCourses()->commit($student, $f->section->id, $review['review_token']);
        self::assertSame($target->value, $academic->auditFacts($credit, $student)['credit']['course_id']);
        self::assertSame(1500, $academic->registrationLoadFacts(
            $enrollment,
            new \Domain\Shared\EventSourcing\HistoryReadBudget()
        )['credits_milli']);
        self::assertSame(1500, $s->enrollmentLedger()->summary($enrollment->id)['selected_credits_milli']);
    }

    private function offering(): OfferingCreditFixture
    {
        $f = new OfferingCreditFixture();
        $f->range();
        $f->bind();
        $f->choose(3000, true);
        $f->select($f->publish());
        return $f;
    }
}
