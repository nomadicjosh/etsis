<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\ValueObject\AccountId;
use Domain\Gradebook\GradeDefinition;
use Domain\Registration\ValueObject\RegistrationPlanSelection;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class CourseDropPlanTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    private function fixture(): array
    {
        $s = new SisFixture();
        $student = $s->student();
        $a = $s->section(fee: 100);
        $b = $s->section(fee: 300);
        $s->service->register($student->id, $a->id, true);
        $s->service->register($student->id, $b->id, true);
        return [$s, new RegistrationPlanSelection($student->id, $a->id, $b->id), $a, $b];
    }
    private function snapshot(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), ['sis_enrollments', 'sis_academic_credits', 'sis_account_balances',
            'sis_course_drop_receipts', 'sis_registration_batches', 'sis_registration_streams',
            'sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_audit']);
    }
    private function confirm($drops, RegistrationPlanSelection $selection, array $review, bool $staff = true): array
    {
        return $drops->commitPlan(
            $selection,
            new EntityId($review['operation_id']),
            $review['revision'],
            $review['review_token'],
            $staff
        );
    }
    public function testTwoEarlyDropsReverseTheirChargesTogetherAndRetriesDoNotDropReplacementEnrollments(): void
    {
        [$s, $selection, $a] = $this->fixture();
        $before = $this->snapshot($s);
        $review = $s->drops->reviewPlan($selection, true);
        self::assertCount(2, $review['courses']);
        self::assertArrayNotHasKey('credit', $review['courses'][0]);
        self::assertArrayNotHasKey('enrollment_id', $review['courses'][0]);
        self::assertArrayHasKey('institutional_id', $review['student']);
        self::assertSame($before, $this->snapshot($s));
        $result = $this->confirm($s->drops, $selection, $review);
        self::assertSame(2, $result['removed']);
        self::assertSame(0, $result['withdrawn']);
        self::assertCount(2, $s->sql->rows('sis_course_drop_receipts'));
        self::assertSame([], $s->sql->rows('sis_enrollments'));
        self::assertSame([], $s->sql->rows('sis_academic_credits'));
        self::assertSame(0, (int) $s->sql->rows('sis_account_balances')[0]['balance_minor']);
        $replacement = $s->service->register($selection->student, $a->id, true);
        $s->clock->time = $s->clock->time->modify('+1 day');
        $before = $this->snapshot($s);
        self::assertSame($result, $this->confirm($s->drops, $selection, $review));
        self::assertSame($before, $this->snapshot($s));
        self::assertSame(
            $replacement->id->value,
            $s->registration->enrollment($selection->student, $a->id)->id->value
        );
        self::assertSame($result, $s->drops->planReceipt(new EntityId($review['operation_id']), true));
    }
    public function testMixedEarlyAndLateDropsPreserveIndependentTranscriptAndChargeEffects(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $a = $s->section(fee: 100);
        $b = $s->section(fee: 300);
        $selection = new RegistrationPlanSelection($student->id, $a->id, $b->id);
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'W', 0, false, false));
        $term = $s->sql->one('sis_terms', ['id' => $s->term->value]);
        $term['id'] = EntityId::generate()->value;
        $term['code'] = 'FICTIONAL.LATE';
        $term['add_drop_ends_on'] = '2026-08-05';
        $s->sql->insert('sis_terms', $term);
        $s->registration->configureWindow(new EntityId($term['id']), $s->registration->window($s->term));
        $s->sql->update('sis_sections', ['term_id' => $term['id']], ['id' => $b->id->value]);
        $s->service->register($selection->student, $a->id, true);
        $s->service->register($selection->student, $b->id, true);
        $review = $s->drops->reviewPlan($selection, true);
        self::assertSame([true, false], array_column(array_column($review['courses'], 'outcome'), 'delete_records'));
        $receipt = $this->confirm($s->drops, $selection, $review);
        self::assertSame(1, $receipt['removed']);
        self::assertSame(1, $receipt['withdrawn']);
        self::assertNull($s->registration->enrollment($selection->student, $a->id));
        self::assertSame('W', $s->registration->enrollment($selection->student, $b->id)->status->value);
        $late = $s->sql->rows('sis_academic_credits')[0];
        self::assertSame(0, (int) $late['completed_milli']);
        $account = new AccountId($s->sql->one('sis_student_accounts', ['student_id' => $selection->student->value,
            'term_id' => $term['id']])['id']);
        self::assertSame(300, $s->accounts->get($account)->balance()->minor);
    }
    public function testTheSecondChildAuditFailureRollsBackEveryDropChargeReceiptAndAudit(): void
    {
        [$s, $selection] = $this->fixture();
        $calls = 0;
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function (EntityId $actor, string $action, EntityId $id)
 use ($s, &$calls): void {
            $s->audit->record($actor, $action, $id);
            if (++$calls === 2) {
                throw new \RuntimeException('Fictional failed required audit');
            }
        });
        $drops = $s->dropService(audit: $audit);
        $review = $drops->reviewPlan($selection, true);
        $before = $this->snapshot($s);
        try {
            $this->confirm($drops, $selection, $review);
            self::fail('A partial set of drops was committed.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fictional failed required audit', $error->getMessage());
            self::assertSame($before, $this->snapshot($s));
        }
    }
    public function testExpiryAndFinalPermissionLossCannotCommitPartialDrops(): void
    {
        [$s, $selection] = $this->fixture();
        $review = $s->drops->reviewPlan($selection, true);
        $s->clock->time = $s->clock->time->modify('+20 minutes');
        $before = $this->snapshot($s);
        try {
            $this->confirm($s->drops, $selection, $review);
            self::fail('An expired drop review was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($s));
        }
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($s): void {
            $s->access->staff = false;
        });
        $drops = $s->dropService(audit: $audit);
        $review = $drops->reviewPlan($selection, true);
        try {
            $this->confirm($drops, $selection, $review);
            self::fail('Lost authority committed a drop.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->snapshot($s));
        }
    }
    public function testOwnersCanReviewTheirCoursesButNotAnotherStudentOrAStaffConfirmation(): void
    {
        [$s, $selection] = $this->fixture();
        $owner = $s->admissions->person($s->admissions->student($selection->student)->personId)->ownerUserId;
        $s->access->actor = $owner;
        $s->access->staff = false;
        $review = $s->drops->reviewPlan($selection, false);
        self::assertCount(2, $s->drops->planChoices($selection->student, false)['items']);
        $before = $this->snapshot($s);
        try {
            $this->confirm($s->drops, $selection, $review, true);
            self::fail('An owner used the staff channel.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->snapshot($s));
        }
        $result = $this->confirm($s->drops, $selection, $review, false);
        self::assertSame(2, $result['removed']);
        $s->access->actor = $s->user();
        $this->expectException(AccessDeniedException::class);
        $s->drops->planReceipt(new EntityId($review['operation_id']), false);
    }
    public function testCurrentChoicesArePagedAndLiteralSearchCannotEscapeTheSelectedStudent(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $s->sql->update('sis_reg_windows', ['course_limit' => 100], ['term_id' => $s->term->value]);
        for ($i = 0; $i < 22; $i++) {
            $section = $s->section(20, 0);
            $s->service->register($student->id, $section->id, true);
        }
        $first = $s->drops->planChoices($student->id, true);
        self::assertCount(20, $first['items']);
        $second = $s->drops->planChoices($student->id, true, after: new EntityId($first['next_after']));
        self::assertCount(2, $second['items']);
        self::assertNull($second['next_after']);
        self::assertCount(22, array_unique(array_column([...$first['items'], ...$second['items']], 'id')));
        self::assertSame([], $s->drops->planChoices($student->id, true, '%')['items']);
        self::assertSame([], $s->drops->planChoices($s->student()->id, true)['items']);
    }

    public function testAnExpiredFinalCoordinatorAuditRollsBackCompletedChildrenAndTheReceipt(): void
    {
        [$s, $selection] = $this->fixture();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function (EntityId $actor, string $action, EntityId $id)
 use ($s): void {
            $s->audit->record($actor, $action, $id);
            if ($action === 'registration.drop_plan_completed') {
                $s->clock->time = $s->clock->time->modify('+20 minutes');
            }
        });
        $drops = $s->dropService(audit: $audit);
        $review = $drops->reviewPlan($selection, true);
        $before = $this->snapshot($s);
        try {
            $this->confirm($drops, $selection, $review);
            self::fail('Expired completion retained child drops.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($s));
        }
    }
    public function testAcademicVerificationSharesOneBoundedAllowanceAcrossTheSelection(): void
    {
        [$s, $selection] = $this->fixture();
        $before = $this->snapshot($s);
        try {
            $s->drops->reviewPlan(
                $selection,
                true,
                new \Domain\Shared\EventSourcing\HistoryReadBudget(maximumPages: 1, maximumEvents: 1)
            );
            self::fail('The group review reset its allowance for each course.');
        } catch (\Domain\Shared\EventSourcing\HistoryReadLimitException) {
            self::assertSame($before, $this->snapshot($s));
        }
        $review = $s->drops->reviewPlan($selection, true);
        try {
            $s->drops->commitPlan(
                $selection,
                new EntityId($review['operation_id']),
                $review['revision'],
                $review['review_token'],
                true,
                new \Domain\Shared\EventSourcing\HistoryReadBudget(maximumPages: 1, maximumEvents: 1)
            );
            self::fail('The group confirmation reset its allowance for each course.');
        } catch (\Domain\Shared\EventSourcing\HistoryReadLimitException) {
            self::assertSame($before, $this->snapshot($s));
        }
    }
}
