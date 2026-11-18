<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AdmissionOffersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    private function fixture(bool $required = true): array
    {
        $f = new SisFixture();
        $f->admissionPolicyService()->save('offers', $required, 0);
        $person = $f->legacyPerson($f->access->actor, 'Fictional', 'Applicant');
        $id = $f->legacyApplication($person->id, $f->program, $f->term)->id;
        $s = $f->decisionService();
        $s->commit($id, $s->review($id, true, 'Fictional admission decision.')['review_token']);
        return [$f, $id];
    }
    private function issue(SisFixture $f, EntityId $id, string $deadline = ''): array
    {
        $s = $f->offerService();
        return $s->review($id, ['action' => 'issue', 'title' => 'Fictional admission offer',
            'message' => 'Welcome to the fictional institution. Please review your offer.',
            'respond_by' => $deadline, 'reason' => 'Fictional reviewed letter.'], false);
    }
    public function testRequiredAcceptanceIsEnforcedAndTheOwnerResponseRetainsPublishedTerms(): void
    {
        [$f, $id] = $this->fixture();
        $s = $f->offerService();
        $before = $f->sql->rows('sis_history_events');
        $review = $this->issue($f, $id);
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        try {
            $f->matriculationService()->review($id, 'Fictional review.');
            self::fail('Matriculation bypassed required acceptance.');
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows('sis_students'));
        }
        $issued = $s->commit($id, $review['review_token'], false);
        self::assertTrue($issued['offer']['require_acceptance']);
        self::assertSame('pending', $s->current($id, true)['offer']['status']);
        $response = $s->review($id, ['action' => 'accept'], true);
        $accepted = $s->commit($id, $response['review_token'], true);
        $terms = $issued['offer'];
        $terms['status'] = 'accepted';
        self::assertSame($terms, $accepted['offer']);
        self::assertSame('accepted', $f->admissions->application($id)->status->value);
        $f->matriculate($id);
        $before = $f->sql->rows('sis_history_events');
        $f->clock->time = $f->clock->now()->modify('+2 days');
        self::assertSame($accepted, $s->commit($id, $response['review_token'], true));
        self::assertSame($issued, $s->commit($id, $review['review_token'], false));
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        self::assertStringNotContainsString($terms['message'], json_encode($before, JSON_THROW_ON_ERROR));
        $timeline = $f->decisionService()->history($id)['items'];
        self::assertSame(['offer_issue', 'offer_accept'], array_column(array_slice($timeline, -2), 'kind'));
    }
    public function testPolicyChangesApplyToNewOffersAndDoNotReinterpretAnIssuedLetter(): void
    {
        [$f, $id] = $this->fixture();
        $s = $f->offerService();
        $s->commit($id, $this->issue($f, $id)['review_token'], false);
        $f->admissionPolicyService()->save('offers', false, 1);
        try {
            $f->matriculate($id);
            self::fail('A policy edit changed the issued offer.');
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows('sis_students'));
        }
        $s->commit($id, $this->issue($f, $id)['review_token'], false);
        self::assertFalse($s->current($id, true)['offer']['require_acceptance']);
        $f->matriculate($id);
        self::assertCount(1, $f->sql->rows('sis_students'));
    }
    public function testNewAcceptanceNeedsANewOfferAndStaleMatriculationCannotOvertakeAResponse(): void
    {
        [$f, $id] = $this->fixture(false);
        $s = $f->offerService();
        $s->commit($id, $this->issue($f, $id)['review_token'], false);
        $matriculation = $f->matriculationService()->review($id, 'Fictional review.');
        $decline = $s->review($id, ['action' => 'decline'], true);
        $s->commit($id, $decline['review_token'], true);
        try {
            $f->matriculationService()->commit($id, $matriculation['review_token']);
            self::fail('An applicant decline was ignored.');
        } catch (ValidationException | ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows('sis_students'));
        }
        foreach ([false, true] as $accept) {
            $c = $f->correctionService();
            $c->commit($id, $c->review($id, $accept, 'Fictional corrected decision.')['review_token']);
        }
        self::assertNull($s->current($id, true)['offer']);
        self::assertSame('declined', $s->commit($id, $decline['review_token'], true)['offer']['status']);
    }
    public function testExpiredDeadlinesReviewsAndCompetingOffersCannotBeConfirmed(): void
    {
        foreach (['deadline', 'review', 'replacement', 'policy'] as $case) {
            [$f, $id] = $this->fixture();
            $s = $f->offerService();
            $s->commit($id, $this->issue($f, $id, $f->clock->now()->format('Y-m-d'))['review_token'], false);
            $response = $s->review($id, ['action' => 'accept'], true);
            if ($case === 'deadline') {
                $f->clock->time = $f->clock->now()->modify('+1 day');
            } elseif ($case === 'review') {
                $f->clock->time = $f->clock->now()->modify('+21 minutes');
            } elseif ($case === 'replacement') {
                $s->commit($id, $this->issue($f, $id)['review_token'], false);
            } else {
                $f->admissionPolicyService()->save('offers', false, 1);
            }
            $before = $f->sql->rows('sis_history_events');
            try {
                $s->commit($id, $response['review_token'], true);
                self::fail('A stale or unavailable response was accepted.');
            } catch (ConcurrentModificationException | ValidationException) {
                self::assertSame($before, $f->sql->rows('sis_history_events'));
            }
        }
    }
    public function testOwnerRoutesCannotBeBypassedByStaffAndAddressedLettersDoNotTransferWithAnAccountLink(): void
    {
        [$f, $id] = $this->fixture();
        $s = $f->offerService();
        $s->commit($id, $this->issue($f, $id)['review_token'], false);
        $original = $f->access->actor;
        $response = $s->review($id, ['action' => 'accept'], true);
        $other = $f->user();
        $f->access->actor = $other; // still a staff superuser in the fictional fixture.
        foreach (['read', 'respond', 'retry'] as $action) {
            try {
                match ($action) {
                    'read' => $s->current($id, true),
                    'respond' => $s->review($id, ['action' => 'accept'], true),
                    'retry' => $s->commit($id, $response['review_token'], true),
                };
                self::fail('Staff bypassed owner authority.');
            } catch (AccessDeniedException | ValidationException) {
                self::assertSame('accepted', $f->admissions->application($id)->status->value);
            }
        }
        $f->sql->update(
            'sis_persons',
            ['owner_user_id' => $other->value],
            ['id' => $f->admissions->application($id)->personId->value]
        );
        self::assertNull($s->current($id, true)['offer']);
        $f->access->actor = $original;
        $this->expectException(AccessDeniedException::class);
        $s->current($id, true);
    }
    public function testIndependentAuthorityAndRequiredAuditAreRecheckedAtomically(): void
    {
        foreach (['audit', 'permission'] as $failure) {
            [$f, $id] = $this->fixture();
            $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
            $audit->expects(self::once())->method('record')->willReturnCallback(static function () use (
                $f,
                $failure
            ): void {
                if ($failure === 'audit') {
                    throw new \RuntimeException('Fictional required audit failure.');
                }
                $f->access->staff = false;
                $f->access->grants = ['sis:admissions:manage'];
            });
            $s = $f->offerService($audit);
            $review = $this->issue($f, $id);
            $before = $f->sql->rows('sis_history_events');
            $version = $f->admissions->application($id)->version;
            try {
                $s->commit($id, $review['review_token'], false);
                self::fail('A missing audit or revoked authority committed the offer.');
            } catch (\RuntimeException | AccessDeniedException) {
                self::assertSame($before, $f->sql->rows('sis_history_events'));
                self::assertSame($version, $f->admissions->application($id)->version);
            }
        }
    }
    public function testOriginalOfferAndResponseReceiptsSurviveReplacementExpiryAndMatriculation(): void
    {
        [$f, $id] = $this->fixture();
        $s = $f->offerService();
        $issue = $this->issue($f, $id);
        $issued = $s->commit($id, $issue['review_token'], false);
        $response = $s->review($id, ['action' => 'accept'], true);
        $accepted = $s->commit($id, $response['review_token'], true);
        $replacement = $this->issue($f, $id);
        $s->commit($id, $replacement['review_token'], false);
        $s->commit($id, $s->review($id, ['action' => 'accept'], true)['review_token'], true);
        $f->matriculate($id);
        $before = $f->sql->rows('sis_history_events');
        $f->clock->time = $f->clock->now()->modify('+30 days');
        self::assertSame($issued, $s->commit($id, $issue['review_token'], false));
        self::assertSame($accepted, $s->commit($id, $response['review_token'], true));
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        self::assertSame($replacement['offer']['id'], $s->current($id, true)['offer']['id']);
        self::assertCount(1, $f->sql->rows('sis_admission_updates'));
        self::assertStringNotContainsString(
            'Welcome to the fictional institution.',
            json_encode($before, JSON_THROW_ON_ERROR)
        );
    }
    public function testAcceptedOffersDoNotExpireButStaffCancellationRequiresAFreshOffer(): void
    {
        [$f, $id] = $this->fixture();
        $s = $f->offerService();
        $s->commit($id, $this->issue($f, $id, $f->clock->now()->format('Y-m-d'))['review_token'], false);
        $s->commit($id, $s->review($id, ['action' => 'accept'], true)['review_token'], true);
        $f->clock->time = $f->clock->now()->modify('+1 day');
        self::assertArrayHasKey('review_token', $f->matriculationService()->review($id, 'Fictional review.'));
        $cancel = $s->review($id, ['action' => 'cancel', 'reason' => 'Fictional revised offer pending.'], false);
        $s->commit($id, $cancel['review_token'], false);
        $this->expectException(ValidationException::class);
        $f->matriculationService()->review($id, 'Fictional review.');
    }
    public function testOrdinaryAdmissionsAuthorityDoesNotGrantOfferOrPolicyWrites(): void
    {
        [$f, $id] = $this->fixture();
        $f->access->staff = false;
        $f->access->grants = ['sis:admissions:manage'];
        self::assertNull($f->offerService()->current($id, false)['offer']);
        $before = $f->sql->rows('sis_history_events');
        foreach (['issue', 'policy'] as $action) {
            try {
                if ($action === 'issue') {
                    $this->issue($f, $id);
                } else {
                    $f->admissionPolicyService()->save('offers', false, 1);
                }
                self::fail('Ordinary admissions authority performed an independently authorized write.');
            } catch (AccessDeniedException) {
                self::assertSame($before, $f->sql->rows('sis_history_events'));
            }
        }
    }
    public function testPolicyAndNotificationAuditFailuresRollBackWithoutAddingEvents(): void
    {
        [$f, $id] = $this->fixture();
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit->expects(self::exactly(2))->method('record')->willThrowException(
            new \RuntimeException('Fictional audit failure.')
        );
        $s = $f->offerService($audit);
        $before = $f->sql->rows('sis_history_events');
        try {
            $f->admissionPolicyService($audit)->save('offers', false, 1);
            self::fail('An unaudited policy was committed.');
        } catch (\RuntimeException) {
            self::assertSame(['require_acceptance' => true, 'revision' => 1], $f->admissionPolicy()->offers());
        }
        $issue = $this->issue($f, $id);
        try {
            $s->commit($id, $issue['review_token'], false);
            self::fail('An unaudited letter was committed.');
        } catch (\RuntimeException) {
            self::assertSame([], $f->sql->rows('sis_admission_updates'));
            self::assertSame($before, $f->sql->rows('sis_history_events'));
        }
    }
    public function testOlderAcceptedApplicationsAreObservedWithoutInventingTheirDecisionHistory(): void
    {
        $f = new SisFixture();
        $p = $f->legacyPerson($f->access->actor, 'Fictional', 'Older applicant');
        $id = $f->legacyApplication($p->id, $f->program, $f->term)->id;
        $f->sql->update('sis_applications', ['status' => 'accepted', 'version' => 1], ['id' => $id->value]);
        $s = $f->offerService();
        $s->commit($id, $this->issue($f, $id)['review_token'], false);
        self::assertFalse($f->decisionLedger()->hasRecordedAcceptance($f->admissions->application($id)));
        $current = $f->decisionLedger()->current($f->admissions->application($id));
        self::assertFalse($current['history_recorded']);
        self::assertNull($current['effective_at']);
        $timeline = $f->decisionService()->history($id);
        self::assertFalse($timeline['history_recorded']);
        self::assertArrayNotHasKey('decision', $timeline);
        self::assertSame(['observation', 'offer_issue'], array_column($timeline['items'], 'kind'));
        $f->matriculate($id);
        self::assertTrue($f->matriculationService()->history($id)['acceptance_history_unknown']);
    }

    public function testOfferPermissionsAreProtectedRootsWithoutAutomaticRoleGrants(): void
    {
        $f = new SisFixture();
        foreach (['sis:admissions:offers', 'sis:admissions:policy'] as $name) {
            $p = $f->sql->one('sis_access_permissions', ['name' => $name]);
            self::assertSame(1, (int) $p['is_system']);
            self::assertSame([], $f->sql->rows('sis_access_role_permissions', ['permission_id' => $p['id']]));
            $edges = $f->sql->rows('sis_access_implications', ['descendant_id' => $p['id']]);
            self::assertCount(1, $edges);
            self::assertSame($p['id'], $edges[0]['ancestor_id']);
        }
    }
    public function testBoundedMultilingualLettersAndPrivateReasonsRemainConfirmable(): void
    {
        [$f, $id] = $this->fixture();
        $s = $f->offerService();
        $letter = str_repeat('🎓', 10000);
        $review = $s->review($id, ['action' => 'issue', 'title' => 'Fictional multilingual letter',
            'message' => $letter, 'reason' => str_repeat('学', 10000)], false);
        $result = $s->commit($id, $review['review_token'], false);
        self::assertSame($letter, $result['offer']['message']);
        self::assertSame($letter, $s->current($id, true)['offer']['message']);
    }
}
