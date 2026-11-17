<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use DomainException;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AdmissionReviewStateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    private function fixture(): array
    {
        $f = new SisFixture();
        $person = $f->legacyPerson($f->access->actor, 'Fictional', 'Applicant');
        $application = $f->legacyApplication($person->id, $f->program, $f->term);
        return [$f, $application->id];
    }
    public function testWithdrawalPreservesPersonAndStudentFactsForEachUnmatriculatedStatus(): void
    {
        foreach (['submitted', 'accepted', 'rejected'] as $status) {
            [$f, $id] = $this->fixture();
            if ($status !== 'submitted') {
                $s = $f->decisionService();
                $s->commit($id, $s->review($id, $status === 'accepted', 'Fictional decision.')['review_token']);
            }
            $before = $f->sql->rows('sis_history_events');
            $people = $f->sql->rows('sis_persons');
            $service = $f->reviewStateService();
            $review = $service->review($id, true, 'Fictional private withdrawal reason.');
            self::assertSame($status, $review['previous_status']);
            self::assertSame($before, $f->sql->rows('sis_history_events'));
            self::assertSame($status, $f->admissions->application($id)->status->value);
            $result = $service->commit($id, $review['review_token']);
            self::assertSame('withdrawn', $result['status']);
            $metrics = new \Infrastructure\Persistence\Repository\SqlWorkspaceRepository(
                $f->sql,
                $f->gradeCatalog(),
                $f->clock
            )->metrics(['sis:admissions:manage']);
            $pipeline = array_column($metrics['pipeline'], 'value', 'label');
            self::assertSame(1, $pipeline['Withdrawn']);
            self::assertSame(0, $pipeline['Submitted']);
            self::assertSame($people, $f->sql->rows('sis_persons'));
            self::assertSame([], $f->sql->rows('sis_students'));
            $items = $f->decisionService()->history($id)['items'];
            self::assertSame('withdrawal', $items[array_key_last($items)]['kind']);
            self::assertSame($status, $items[array_key_last($items)]['previous_status']);
            self::assertStringNotContainsString('Fictional private withdrawal reason.', json_encode([
                $items, $f->sql->rows('sis_history_events')], JSON_THROW_ON_ERROR));
            self::assertSame($before, array_slice($f->sql->rows('sis_history_events'), 0, count($before)));
        }
    }
    public function testReturnToReviewRequiresANewDecisionAndOriginalReceiptsSurviveLaterMatriculation(): void
    {
        [$f, $id] = $this->fixture();
        $decisions = $f->decisionService();
        $acceptedReview = $decisions->review($id, true, 'Fictional acceptance.');
        $accepted = $decisions->commit($id, $acceptedReview['review_token']);
        $oldMatriculation = $f->matriculationService()->review($id, 'Fictional initial review.');
        $state = $f->reviewStateService();
        $withdrawal = $state->review($id, true, 'Fictional cancellation.');
        $withdrawn = $state->commit($id, $withdrawal['review_token']);
        $resumption = $state->review($id, false, 'Fictional request for reconsideration.');
        $resumed = $state->commit($id, $resumption['review_token']);
        self::assertSame('submitted', $resumed['status']);
        self::assertSame($accepted, $decisions->commit($id, $acceptedReview['review_token']));
        self::assertSame('submitted', $f->admissions->application($id)->status->value);
        try {
            $f->matriculate($id);
            self::fail('Returning to review restored an earlier acceptance.');
        } catch (ValidationException | DomainException) {
            self::assertSame([], $f->sql->rows('sis_students'));
        }
        $newAcceptance = $decisions->review($id, true, 'Fictional fresh acceptance.');
        $decisions->commit($id, $newAcceptance['review_token']);
        try {
            $f->matriculationService()->commit($id, $oldMatriculation['review_token']);
            self::fail('An old matriculation review survived a new acceptance cycle.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows('sis_students'));
        }
        $f->matriculate($id);
        $before = $f->sql->rows('sis_history_events');
        $f->clock->time = $f->clock->now()->modify('+1 day');
        self::assertSame($withdrawn, $state->commit($id, $withdrawal['review_token']));
        self::assertSame($resumed, $state->commit($id, $resumption['review_token']));
        self::assertSame(ApplicationStatus::Matriculated, $f->admissions->application($id)->status);
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        self::assertCount(1, $f->sql->rows('sis_students'));
    }
    public function testWithdrawingAnotherApplicationDoesNotWithdrawAnExistingStudent(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $id = $f->legacyApplication($student->personId, $f->program, $f->term)->id;
        $students = $f->sql->rows('sis_students');
        $people = $f->sql->rows('sis_persons');
        $state = $f->reviewStateService();
        $state->commit($id, $state->review($id, true, 'Fictional application cancellation.')['review_token']);
        $state->commit($id, $state->review($id, false, 'Fictional application reconsideration.')['review_token']);
        self::assertSame($students, $f->sql->rows('sis_students'));
        self::assertSame($people, $f->sql->rows('sis_persons'));
        self::assertSame('Withdrawn', \Infrastructure\Reporting\AcademicReportSchema::datasets()
            ['applications']['fields']['status']['choices']['withdrawn']);
    }
    public function testNewWithdrawalOrReturnIsBlockedAfterMatriculation(): void
    {
        [$f, $id] = $this->fixture();
        $decisions = $f->decisionService();
        $decisions->commit($id, $decisions->review($id, true, 'Fictional decision.')['review_token']);
        $f->matriculate($id);
        $before = $f->sql->rows('sis_history_events');
        foreach ([true, false] as $withdraw) {
            try {
                $f->reviewStateService()->review($id, $withdraw, 'Fictional inadmissible change.');
                self::fail('A new post-matriculation application withdrawal was accepted.');
            } catch (DomainException) {
                self::assertSame($before, $f->sql->rows('sis_history_events'));
                self::assertSame(ApplicationStatus::Matriculated, $f->admissions->application($id)->status);
            }
        }
    }
    public function testCompetingDecisionAndWithdrawalReviewsCannotOvertakeEachOther(): void
    {
        foreach ([true, false] as $withdrawWins) {
            [$f, $id] = $this->fixture();
            $decisions = $f->decisionService();
            $state = $f->reviewStateService();
            $acceptance = $decisions->review($id, true, 'Fictional decision.');
            $withdrawal = $state->review($id, true, 'Fictional cancellation.');
            if ($withdrawWins) {
                $state->commit($id, $withdrawal['review_token']);
            } else {
                $decisions->commit($id, $acceptance['review_token']);
            }
            $before = $f->sql->rows('sis_history_events');
            try {
                if ($withdrawWins) {
                    $decisions->commit($id, $acceptance['review_token']);
                } else {
                    $state->commit($id, $withdrawal['review_token']);
                }
                self::fail('A stale competing review was accepted.');
            } catch (ValidationException | ConcurrentModificationException) {
                self::assertSame($before, $f->sql->rows('sis_history_events'));
                self::assertSame(
                    $withdrawWins ? 'withdrawn' : 'accepted',
                    $f->admissions->application($id)->status->value
                );
            }
        }
    }
    public function testAnEarlierReturnReviewCannotApplyAfterAnotherWithdrawalCycle(): void
    {
        [$f, $id] = $this->fixture();
        $state = $f->reviewStateService();
        $state->commit($id, $state->review($id, true, 'Fictional cancellation.')['review_token']);
        $old = $state->review($id, false, 'Fictional earlier reconsideration.');
        $state->commit($id, $state->review($id, false, 'Fictional resumption.')['review_token']);
        $state->commit($id, $state->review($id, true, 'Fictional later cancellation.')['review_token']);
        $this->expectException(ConcurrentModificationException::class);
        $state->commit($id, $old['review_token']);
    }
    public function testOwnersAndOrdinaryAdmissionsUsersDoNotReceiveWithdrawalAuthority(): void
    {
        [$f, $id] = $this->fixture();
        $f->access->staff = false;
        foreach ([[], ['sis:admissions:manage'], ['sis:admissions:withdraw'], ['sis:student:read']] as $grants) {
            $f->access->grants = $grants;
            try {
                $f->reviewStateService()->review($id, true, 'Fictional request.');
                self::fail('Partial authority changed admissions review state.');
            } catch (AccessDeniedException) {
                self::assertSame([], $f->sql->rows('sis_history_events'));
            }
        }
        $f->access->grants = ['sis:admissions:manage', 'sis:admissions:withdraw'];
        $state = $f->reviewStateService();
        $state->commit($id, $state->review($id, true, 'Fictional authorized withdrawal.')['review_token']);
        self::assertSame(ApplicationStatus::Withdrawn, $f->admissions->application($id)->status);
    }
    public function testExpiredChangedAndForeignActorReviewsRequireFreshReview(): void
    {
        foreach (['expired', 'person', 'actor'] as $change) {
            [$f, $id] = $this->fixture();
            $state = $f->reviewStateService();
            $review = $state->review($id, true, 'Fictional review.');
            if ($change === 'expired') {
                $f->clock->time = $f->clock->now()->modify('+21 minutes');
            } elseif ($change === 'person') {
                $f->sql->update(
                    'sis_persons',
                    ['given_name' => 'Changed fictional'],
                    ['id' => $f->admissions->application($id)->personId->value]
                );
            } else {
                $f->access->actor = $f->user();
            }
            try {
                $state->commit($id, $review['review_token']);
                self::fail('A stale or foreign review was accepted.');
            } catch (ConcurrentModificationException | ValidationException) {
                self::assertSame([], $f->sql->rows('sis_history_events'));
                self::assertSame(ApplicationStatus::Submitted, $f->admissions->application($id)->status);
            }
        }
    }
    public function testRequiredAuditAndCurrentAuthorityAreAtomicWithTheReviewState(): void
    {
        foreach (['audit', 'permission'] as $failure) {
            [$f, $id] = $this->fixture();
            $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
            $audit->expects(self::once())->method('record')->willReturnCallback(static function () use (
                $failure,
                $f
            ): void {
                if ($failure === 'audit') {
                    throw new \RuntimeException('Fictional required audit failure.');
                }
                $f->access->staff = false;
                $f->access->grants = [];
            });
            $state = $f->reviewStateService(audit: $audit);
            $review = $state->review($id, true, 'Fictional review.');
            try {
                $state->commit($id, $review['review_token']);
                self::fail('A failed confirmation left a changed application.');
            } catch (\RuntimeException | AccessDeniedException) {
                self::assertSame([], $f->sql->rows('sis_history_events'));
                self::assertSame(ApplicationStatus::Submitted, $f->admissions->application($id)->status);
            }
        }
    }
    public function testAnUntrackedWithdrawnProjectionCannotBeTreatedAsRecordedHistory(): void
    {
        [$f, $id] = $this->fixture();
        $f->sql->update('sis_applications', ['status' => 'withdrawn'], ['id' => $id->value]);
        $this->expectException(CorruptHistoryException::class);
        $f->reviewStateService()->review($id, false, 'Fictional resumption.');
    }
}
