<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AdmissionWithdrawalRequestsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    private function fixture(): array
    {
        $f = new SisFixture();
        $owner = $f->access->actor;
        $person = $f->legacyPerson($owner, 'Fictional', 'Applicant');
        $id = $f->legacyApplication($person->id, $f->program, $f->term)->id;
        return [$f, $id, $owner];
    }
    private function request(SisFixture $f, EntityId $id): array
    {
        $s = $f->withdrawalService();
        return $s->review($id, 'request', null, 'Fictional private applicant request.', true);
    }
    public function testApprovalByDefaultKeepsWorkingRequestsOutOfTheJournalUntilApproved(): void
    {
        [$f, $id, $owner] = $this->fixture();
        $s = $f->withdrawalService();
        self::assertSame(['mode' => 'request', 'revision' => 0], $f->admissionPolicy()->withdrawals());
        $review = $this->request($f, $id);
        self::assertSame([], $f->sql->rows('sis_admission_withdrawal_requests'));
        $sent = $s->commit($id, $review['review_token'], true);
        self::assertSame('pending', $sent['status']);
        self::assertSame([], $f->sql->rows('sis_history_events'));
        self::assertSame('submitted', $f->admissions->application($id)->status->value);
        self::assertCount(1, $s->queue()['items']);
        self::assertStringNotContainsString('private applicant request', json_encode(
            $f->sql->rows('sis_admission_withdrawal_requests'),
            JSON_THROW_ON_ERROR
        ));
        $staff = $f->user();
        $f->access->actor = $staff;
        $approve = $s->review(
            $id,
            'approve',
            new EntityId($sent['request_id']),
            'Your withdrawal request was approved.',
            false
        );
        $done = $s->commit($id, $approve['review_token'], false);
        self::assertSame('approved', $done['status']);
        self::assertSame('withdrawn', $f->admissions->application($id)->status->value);
        self::assertSame([], $f->sql->rows('sis_students'));
        self::assertCount(2, $f->sql->rows('sis_history_events'));
        self::assertSame('staff', $f->decisionService()->history($id)['items'][1]['withdrawal_channel']);
        self::assertSame([], $s->queue()['items']);
        $f->access->actor = $owner;
        self::assertSame($sent, $s->commit($id, $review['review_token'], true));
        self::assertSame('Your withdrawal request was approved.', $s->current($id, true)['items'][0]['response']);
    }
    public function testDirectModeUsesTheSameFormalWithdrawalAndNeverChangesAPersonOrExistingStudent(): void
    {
        [$f, $id] = $this->fixture();
        $f->admissionPolicyService()->save('withdrawals', 'direct', 0);
        $people = $f->sql->rows('sis_persons');
        $s = $f->withdrawalService();
        $review = $s->review($id, 'direct', null, 'Fictional change of plans.', true);
        $result = $s->commit($id, $review['review_token'], true);
        self::assertSame('withdrawn', $result['application_status']);
        self::assertSame($people, $f->sql->rows('sis_persons'));
        self::assertSame([], $f->sql->rows('sis_students'));
        self::assertSame('applicant', $f->decisionService()->history($id)['items'][1]['withdrawal_channel']);
        $state = $f->reviewStateService();
        $state->commit($id, $state->review($id, false, 'Fictional reconsideration.')['review_token']);
        $f->clock->time = $f->clock->now()->modify('+1 day');
        self::assertSame($result, $s->commit($id, $review['review_token'], true));
        self::assertSame('submitted', $f->admissions->application($id)->status->value);
    }
    public function testCancellingDoesNotWithdrawAndOldReviewsCannotReappearAfterAnotherRequestCycle(): void
    {
        [$f, $id] = $this->fixture();
        $s = $f->withdrawalService();
        $older = $this->request($f, $id);
        $sentReview = $this->request($f, $id);
        $sent = $s->commit($id, $sentReview['review_token'], true);
        $cancel = $s->review($id, 'cancel', new EntityId($sent['request_id']), '', true);
        $cancelled = $s->commit($id, $cancel['review_token'], true);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame([], $f->sql->rows('sis_history_events'));
        try {
            $s->commit($id, $older['review_token'], true);
            self::fail('An earlier create review survived a complete request/cancellation cycle.');
        } catch (ConcurrentModificationException) {
            self::assertFalse($s->current($id, true)['has_pending']);
        }
        $later = $s->commit($id, $this->request($f, $id)['review_token'], true);
        self::assertSame($sent, $s->commit($id, $sentReview['review_token'], true));
        self::assertSame($cancelled, $s->commit($id, $cancel['review_token'], true));
        self::assertSame($later['request_id'], $s->current($id, true)['pending_id']);
    }
    public function testDecliningARequestLeavesTheApplicationAndExistingOfferUntouched(): void
    {
        [$f, $id] = $this->fixture();
        $s = $f->withdrawalService();
        $sent = $s->commit($id, $this->request($f, $id)['review_token'], true);
        $decline = $s->review(
            $id,
            'decline',
            new EntityId($sent['request_id']),
            'Please contact admissions to review your options.',
            false
        );
        $s->commit($id, $decline['review_token'], false);
        self::assertSame('submitted', $f->admissions->application($id)->status->value);
        self::assertSame([], $f->sql->rows('sis_history_events'));
        self::assertFalse($s->current($id, true)['has_pending']);
    }
    public function testARequestPausesMatriculationAndOffersAndInvalidatesEarlierReviewsEvenAfterCancellation(): void
    {
        [$f, $id] = $this->fixture();
        $d = $f->decisionService();
        $d->commit($id, $d->review($id, true, 'Fictional decision.')['review_token']);
        $matriculation = $f->matriculationService()->review($id, 'Fictional initial matriculation review.');
        $s = $f->withdrawalService();
        $sent = $s->commit($id, $this->request($f, $id)['review_token'], true);
        try {
            $f->matriculationService()->commit($id, $matriculation['review_token']);
            self::fail('A pending applicant request was ignored.');
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows('sis_students'));
        }
        self::assertFalse($f->offerService()->current($id, true)['response_open']);
        $cancel = $s->review($id, 'cancel', new EntityId($sent['request_id']), '', true);
        $s->commit($id, $cancel['review_token'], true);
        // A fresh review is required even though current pending state returned to none.
        $this->expectException(ConcurrentModificationException::class);
        $f->matriculationService()->commit($id, $matriculation['review_token']);
    }
    public function testOwnersCannotUseStaffActionsOrOtherPeoplesApplicationsEvenAsASuperuser(): void
    {
        [$f, $id] = $this->fixture();
        $s = $f->withdrawalService();
        $sent = $s->commit($id, $this->request($f, $id)['review_token'], true);
        $f->access->actor = $f->user();
        foreach (['read', 'cancel', 'create'] as $action) {
            try {
                match ($action) {
                    'read' => $s->current($id, true),
                    'cancel' => $s->review($id, 'cancel', new EntityId($sent['request_id']), '', true),
                    'create' => $this->request($f, $id),
                };
                self::fail('Staff authority overrode an owner endpoint.');
            } catch (AccessDeniedException) {
                self::assertSame('submitted', $f->admissions->application($id)->status->value);
            }
        }
    }
    public function testStaffOnlyPolicyDisablesNewOwnerRequestsButAllowsExistingRequestCancellation(): void
    {
        [$f, $id] = $this->fixture();
        $s = $f->withdrawalService();
        $sent = $s->commit($id, $this->request($f, $id)['review_token'], true);
        $f->admissionPolicyService()->save('withdrawals', 'staff_only', 0);
        $s->commit($id, $s->review($id, 'cancel', new EntityId($sent['request_id']), '', true)['review_token'], true);
        $this->expectException(ValidationException::class);
        $this->request($f, $id);
    }
    public function testRevokedAuthorityAndRequiredAuditRollBackRequestAndFormalDecisionTogether(): void
    {
        foreach (['audit', 'permission'] as $failure) {
            [$f, $id] = $this->fixture();
            $s = $f->withdrawalService();
            $sent = $s->commit($id, $this->request($f, $id)['review_token'], true);
            $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
            $audit->expects(self::once())->method('record')->willReturnCallback(static function () use (
                $f,
                $failure
            ): void {
                if ($failure === 'audit') {
                    throw new \RuntimeException('Fictional audit failure.');
                }
                $f->access->staff = false;
                $f->access->grants = ['sis:admissions:manage'];
            });
            $s = $f->withdrawalService($audit);
            $review = $s->review($id, 'approve', new EntityId($sent['request_id']), 'Fictional approval.', false);
            try {
                $s->commit($id, $review['review_token'], false);
                self::fail('An unauthorized or unaudited decision committed.');
            } catch (\RuntimeException | AccessDeniedException) {
                self::assertSame('pending', $f->withdrawalRepository()->pending($id)['status']);
                self::assertSame('submitted', $f->admissions->application($id)->status->value);
                self::assertSame([], $f->sql->rows('sis_history_events'));
            }
        }
    }
    public function testOwnerWithNoAdmissionsPermissionsCanRequestAndDirectlyWithdrawUnderInstitutionPolicy(): void
    {
        foreach (['request', 'direct'] as $mode) {
            [$f, $id] = $this->fixture();
            if ($mode === 'direct') {
                $f->admissionPolicyService()->save('withdrawals', 'direct', 0);
            }
            $f->access->staff = false;
            $f->access->grants = [];
            $s = $f->withdrawalService();
            $review = $s->review($id, $mode, null, 'Fictional owner request.', true);
            $result = $s->commit($id, $review['review_token'], true);
            self::assertSame($mode === 'direct' ? 'withdrawn' : 'pending', $result['status']);
        }
    }
    public function testWithdrawalOfAnotherApplicationDoesNotCloseAnExistingStudent(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $f->access->actor = $f->admissions->person($student->personId)->ownerUserId;
        $id = $f->legacyApplication($student->personId, $f->program, $f->term)->id;
        $f->admissionPolicyService()->save('withdrawals', 'direct', 0);
        $people = $f->sql->rows('sis_persons');
        $students = $f->sql->rows('sis_students');
        $s = $f->withdrawalService();
        $review = $s->review($id, 'direct', null, 'Fictional unrelated application closure.', true);
        $s->commit($id, $review['review_token'], true);
        self::assertSame($people, $f->sql->rows('sis_persons'));
        self::assertSame($students, $f->sql->rows('sis_students'));
    }
    public function testOfferPolicyChangesDoNotInvalidateAnUnrelatedWithdrawalRequestReview(): void
    {
        [$f, $id] = $this->fixture();
        $review = $this->request($f, $id);
        $f->admissionPolicyService()->save('offers', true, 0);
        self::assertSame(['mode' => 'request', 'revision' => 0], $f->admissionPolicy()->withdrawals());
        self::assertSame('pending', $f->withdrawalService()->commit($id, $review['review_token'], true)['status']);
    }
    public function testExpiredOrChangedAccountReviewsCannotApplyAndEarlierApplicantWordingDoesNotTransfer(): void
    {
        foreach (['expired', 'owner'] as $change) {
            [$f, $id, $owner] = $this->fixture();
            $s = $f->withdrawalService();
            $sent = $s->commit($id, $this->request($f, $id)['review_token'], true);
            $review = $s->review($id, 'approve', new EntityId($sent['request_id']), 'Fictional approval.', false);
            if ($change === 'expired') {
                $f->clock->time = $f->clock->now()->modify('+21 minutes');
            } else {
                $other = $f->user();
                $f->sql->update(
                    'sis_persons',
                    ['owner_user_id' => $other->value],
                    ['id' => $f->admissions->application($id)->personId->value]
                );
            }
            try {
                $s->commit($id, $review['review_token'], false);
                self::fail('An expired or re-addressed review changed the application.');
            } catch (ConcurrentModificationException) {
                self::assertSame('submitted', $f->admissions->application($id)->status->value);
            }
            if ($change === 'owner') {
                $f->access->actor = $other;
                $read = $s->current($id, true);
                self::assertSame([], $read['items']);
                self::assertNull($read['pending_id']);
                self::assertTrue($read['has_pending']);
            }
        }
    }
    public function testStaffManualWithdrawalMustResolvePendingRequestsAndOldReviewsDoNotSurviveCancellation(): void
    {
        [$f, $id] = $this->fixture();
        $manual = $f->reviewStateService()->review($id, true, 'Fictional staff review.');
        $s = $f->withdrawalService();
        $sent = $s->commit($id, $this->request($f, $id)['review_token'], true);
        try {
            $f->reviewStateService()->commit($id, $manual['review_token']);
            self::fail('A direct staff action ignored a pending request.');
        } catch (ValidationException) {
            self::assertSame('submitted', $f->admissions->application($id)->status->value);
        }
        $s->commit($id, $s->review($id, 'cancel', new EntityId($sent['request_id']), '', true)['review_token'], true);
        $this->expectException(ConcurrentModificationException::class);
        $f->reviewStateService()->commit($id, $manual['review_token']);
    }
}
