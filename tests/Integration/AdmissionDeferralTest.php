<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Admissions\Service\ApplicationFormService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlApplicationFormRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;
use AcademicCalendarFixture;

final class AdmissionDeferralTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/AcademicCalendarFixture.php';
    }
    private function fixture(bool $form = false): array
    {
        $f = new SisFixture();
        $cal = new AcademicCalendarFixture($f);
        $cal->type();
        $year = $cal->year(2027);
        $term = new EntityId($f->sql->rows('sis_term_calendar', ['academic_year_id' => $year['id']], 1)[0]['id']);
        $person = $f->legacyPerson($f->access->actor, 'Fictional', 'Applicant');
        $submission = EntityId::generate();
        $forms = $this->forms($f);
        $formId = null;
        if ($form) {
            $definition = $forms->save(null, $f->program, 'Fictional original form', [
                ['key' => 'statement', 'label' => 'Statement', 'type' => 'textarea', 'required' => true],
            ], null);
            $forms->publish($definition->id, 0);
            $formId = $definition->id;
            $id = $forms->submit(
                $submission,
                $formId,
                $person->id,
                $f->term,
                ['statement' => 'Original fictional answer.']
            );
        } else {
            $id = $submission;
            $f->submissionService()->apply($id, $person->id, $f->program, $f->term);
        }
        $d = $f->decisionService();
        $d->commit($id, $d->review($id, true, 'Fictional admission decision.')['review_token']);
        return [$f, $id, $term, $submission, $formId];
    }
    private function forms(SisFixture $f): ApplicationFormService
    {
        return new ApplicationFormService(
            new SqlApplicationFormRepository($f->sql),
            $f->admissionsService(),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->submissionService()
        );
    }
    private function approve(SisFixture $f, EntityId $id, EntityId $term, string $kind = 'defer'): array
    {
        $s = $f->deferralService();
        return $s->commit($id, $s->review(
            $id,
            $term,
            $kind,
            'Fictional applicant-requested intake approval.'
        )['review_token']);
    }
    private function offer(SisFixture $f, EntityId $id, bool $accept = false): void
    {
        $s = $f->offerService();
        $s->commit($id, $s->review(
            $id,
            ['action' => 'issue', 'title' => 'Fictional new intake offer',
            'message' => 'Please review this fictional intake.', 'reason' => 'Fictional letter approval.'],
            false
        )['review_token'], false);
        if ($accept) {
            $s->commit($id, $s->review($id, ['action' => 'accept'], true)['review_token'], true);
        }
    }
    public function testDeferredApplicationRetainsOriginalAnswersAndSubmissionFactsWithOnePersonAndApplication(): void
    {
        [$f, $id, $term, $submission, $form] = $this->fixture(true);
        $s = $f->deferralService();
        $answers = $f->sql->rows('sis_form_submissions');
        $people = $f->sql->rows('sis_persons');
        $intake = $f->sql->rows('sis_application_intake');
        $events = $f->sql->rows('sis_history_events');
        $review = $s->review($id, $term, 'defer', 'Fictional confidential intake reason.');
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        $result = $s->commit($id, $review['review_token']);
        self::assertSame($term->value, $f->admissions->application($id)->entryTermId->value);
        self::assertSame('accepted', $f->admissions->application($id)->status->value);
        self::assertSame($people, $f->sql->rows('sis_persons'));
        self::assertSame($answers, $f->sql->rows('sis_form_submissions'));
        self::assertSame($intake, $f->sql->rows('sis_application_intake'));
        self::assertCount(1, $f->sql->rows('sis_applications'));
        self::assertSame([], $f->sql->rows('sis_students'));
        self::assertSame(
            'Original fictional answer.',
            $this->forms($f)->submission($submission)['answers']['statement']
        );
        self::assertSame(
            $intake[0]['submitted_term_code'],
            $this->forms($f)->submission($submission)['submitted_term_code']
        );
        self::assertSame($id->value, $this->forms($f)->submit(
            $submission,
            $form,
            $f->admissions->application($id)->personId,
            $f->term,
            ['statement' => 'Original fictional answer.']
        )->value);
        self::assertSame('entry_defer', array_slice($f->decisionService()->history($id)['items'], -1)[0]['kind']);
        self::assertSame($events, array_slice($f->sql->rows('sis_history_events'), 0, count($events)));
        self::assertSame($result, $s->commit($id, $review['review_token']));
    }
    public function testFreshOfferIsNeededEvenWhenAcceptanceIsOptionalAndMatriculationUsesTheNewCohort(): void
    {
        [$f, $id, $term] = $this->fixture();
        $this->offer($f, $id, true);
        $old = $f->matriculationService()->review($id, 'Fictional original matriculation review.');
        $this->approve($f, $id, $term);
        self::assertNull($f->offerService()->current($id, true)['offer']);
        try {
            $f->matriculationService()->commit($id, $old['review_token']);
            self::fail('An earlier offer or matriculation review survived deferment.');
        } catch (ValidationException | ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows('sis_students'));
        }
        $this->offer($f, $id); // Existing institutional policy makes acceptance optional in this fixture.
        $student = $f->matriculate($id);
        self::assertSame($term->value, $student->entryTermId->value);
        $program = $f->sql->rows('sis_student_programs', ['student_id' => $student->id->value], 1)[0];
        $catalogs = new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($f->sql);
        $binding = $catalogs->binding(new EntityId($program['id']));
        self::assertNotNull($binding);
        self::assertSame(
            $f->sql->one('sis_term_calendar', ['id' => $term->value])['academic_year_id'],
            $catalogs->release(new EntityId($binding['catalog_id']))['year_id']
        );
    }
    public function testInstitutionalAcceptancePolicyAppliesToTheNewOffer(): void
    {
        [$f, $id, $term] = $this->fixture();
        $f->admissionPolicyService()->save('offers', true, 0);
        $this->approve($f, $id, $term);
        $this->offer($f, $id);
        try {
            $f->matriculate($id);
            self::fail('Required new applicant acceptance was skipped.');
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows('sis_students'));
        }
        $s = $f->offerService();
        $s->commit($id, $s->review($id, ['action' => 'accept'], true)['review_token'], true);
        $f->matriculate($id);
        self::assertCount(1, $f->sql->rows('sis_students'));
    }
    public function testCalendarChangesRequireAReviewedReconfirmationAndAnotherFreshOffer(): void
    {
        [$f, $id, $term] = $this->fixture();
        $this->approve($f, $id, $term);
        $this->offer($f, $id, true);
        $f->sql->update('sis_terms', ['starts_on' => '2027-08-16'], ['id' => $term->value]);
        self::assertTrue($f->deferralService()->current($id)['needs_review']);
        self::assertFalse($f->offerService()->current($id, true)['response_open']);
        try {
            $f->matriculate($id);
            self::fail('Changed intake silently altered the approved plan.');
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows('sis_students'));
        }
        $this->approve($f, $id, $term, 'reconfirm');
        self::assertNull($f->offerService()->current($id, true)['offer']);
        $this->offer($f, $id);
        $f->matriculate($id);
        self::assertCount(1, $f->sql->rows('sis_students'));
    }

    public function testIndependentPermissionAndActorBindingApplyEvenToCompletedRetries(): void
    {
        [$f, $id, $term] = $this->fixture();
        $s = $f->deferralService();
        $review = $s->review($id, $term, 'defer', 'Fictional intake reason.');
        $f->access->staff = false;
        $f->access->grants = ['sis:admissions:manage'];
        foreach (['current', 'choices', 'review', 'commit'] as $action) {
            try {
                match ($action) {
                    'current' => $s->current($id),
                    'choices' => $s->choices($id, ''),
                    'review' => $s->review($id, $term, 'defer', 'Fictional reason.'),
                    'commit' => $s->commit($id, $review['review_token']),
                };
                self::fail('General management or ownership granted intake approval.');
            } catch (AccessDeniedException) {
                self::assertSame($f->term->value, $f->admissions->application($id)->entryTermId->value);
            }
        }
        $f->access->grants[] = 'sis:admissions:defer';
        $s->commit($id, $review['review_token']);
        $f->access->grants = ['sis:admissions:manage'];
        $this->expectException(AccessDeniedException::class);
        $s->commit($id, $review['review_token']);
    }
    public function testExpiredChangedCalendarAndCompetingReviewsNeverOverwriteALaterApproval(): void
    {
        foreach (['expiry', 'calendar', 'competing', 'actor'] as $change) {
            [$f, $id, $term] = $this->fixture();
            $s = $f->deferralService();
            $review = $s->review($id, $term, 'defer', 'Fictional intake reason.');
            match ($change) {
                'expiry' => $f->clock->time = $f->clock->now()->modify('+21 minutes'),
                'calendar' => $f->sql->update('sis_terms', ['starts_on' => '2027-08-16'], ['id' => $term->value]),
                'competing' => $this->approve($f, $id, $term),
                'actor' => $f->access->actor = $f->user(),
            };
            $before = $f->sql->rows('sis_history_events');
            try {
                $s->commit($id, $review['review_token']);
                self::fail('An obsolete intake review committed.');
            } catch (ValidationException | ConcurrentModificationException) {
                self::assertSame($before, $f->sql->rows('sis_history_events'));
                self::assertSame(
                    $change === 'competing' ? $term->value : $f->term->value,
                    $f->admissions->application($id)->entryTermId->value
                );
            }
        }
    }
    public function testPendingWithdrawalAndACompletedRequestCancellationCycleInvalidateIntakeReviews(): void
    {
        [$f, $id, $term] = $this->fixture();
        $s = $f->deferralService();
        $w = $f->withdrawalService();
        $review = $s->review($id, $term, 'defer', 'Fictional intake reason.');
        $sent = $w->commit($id, $w->review(
            $id,
            'request',
            null,
            'Fictional change of plans.',
            true
        )['review_token'], true);
        try {
            $s->commit($id, $review['review_token']);
            self::fail('Pending withdrawal was ignored.');
        } catch (ValidationException) {
            self::assertSame($f->term->value, $f->admissions->application($id)->entryTermId->value);
        }
        $w->commit($id, $w->review($id, 'cancel', new EntityId($sent['request_id']), '', true)['review_token'], true);
        $this->expectException(ConcurrentModificationException::class);
        $s->commit($id, $review['review_token']);
    }
    public function testAuditFailureAndFinalPermissionRevocationRollBackProjectionEventAndInboxTogether(): void
    {
        foreach (['audit', 'permission'] as $failure) {
            [$f, $id, $term] = $this->fixture();
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
            $s = $f->deferralService($audit);
            $review = $s->review($id, $term, 'defer', 'Fictional intake reason.');
            $events = $f->sql->rows('sis_history_events');
            $inbox = $f->sql->rows('sis_admission_updates');
            try {
                $s->commit($id, $review['review_token']);
                self::fail('An unauthorized or unaudited approval committed.');
            } catch (\RuntimeException | AccessDeniedException) {
                self::assertSame($f->term->value, $f->admissions->application($id)->entryTermId->value);
                self::assertSame($events, $f->sql->rows('sis_history_events'));
                self::assertSame($inbox, $f->sql->rows('sis_admission_updates'));
            }
        }
    }
    public function testOriginalReceiptAndSubmissionRetryDoNotUndoALaterDeferralOrMatriculation(): void
    {
        [$f, $id, $term] = $this->fixture();
        $s = $f->deferralService();
        $original = $f->admissions->application($id);
        $review = $s->review($id, $term, 'defer', 'Fictional intake reason.');
        $result = $s->commit($id, $review['review_token']);
        $year = (new AcademicCalendarFixture($f))->year(2028);
        $later = new EntityId($f->sql->rows('sis_term_calendar', ['academic_year_id' => $year['id']], 1)[0]['id']);
        $this->approve($f, $id, $later);
        $this->offer($f, $id);
        $f->matriculate($id);
        $f->clock->time = $f->clock->now()->modify('+1 day');
        $events = $f->sql->rows('sis_history_events');
        self::assertSame($result, $s->commit($id, $review['review_token']));
        $f->submissionService()->apply($id, $original->personId, $original->programId, $original->entryTermId);
        self::assertSame($later->value, $f->admissions->application($id)->entryTermId->value);
        self::assertSame('matriculated', $f->admissions->application($id)->status->value);
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        self::assertCount(1, $f->sql->rows('sis_students'));
    }
    public function testTermLookupIsBoundedAndOnlyIncludesLaterLinkedIntakes(): void
    {
        [$f, $id, $term] = $this->fixture();
        $rows = $f->deferralService()->choices($id, '27/FA');
        self::assertCount(1, $rows['items']);
        self::assertSame($term->value, $rows['items'][0]['id']);
        self::assertNull($rows['next']);
        self::assertSame([], $f->deferralService()->choices($id, 'not-a-term')['items']);
        self::assertSame([], $f->deferralService()->choices($id, '%')['items']);
        foreach ([$f->term, $term] as $bad) {
            if ($bad === $term) {
                $f->sql->execute('DELETE FROM sis_term_calendar WHERE id = ?', [$term->value]);
            }
            try {
                $this->approve($f, $id, $bad);
                self::fail('A current or unlinked term was approved.');
            } catch (ValidationException) {
                self::assertSame($f->term->value, $f->admissions->application($id)->entryTermId->value);
            }
        }
    }

    public function testSubmissionIndexRestorationUsesTheOpeningEventAndRejectsChangedRecordedIntakes(): void
    {
        [$f, $id, $term] = $this->fixture(true);
        $original = $f->sql->one('sis_application_intake', ['id' => $id->value]);
        $this->approve($f, $id, $term);
        $f->sql->update('sis_application_intake', ['submitted_term_id' => null,
            'submitted_term_code' => null], ['id' => $id->value]);
        $upgrade = new \Infrastructure\EventSourcing\SubmissionProjectionUpgrade(
            $f->connection->queryBuilder(),
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                \EventStoreFixture::config($f->connection->queryBuilder())
            )
        );
        $events = $f->sql->rows('sis_history_events');
        $upgrade->run();
        self::assertSame($original, $f->sql->one('sis_application_intake', ['id' => $id->value]));
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        $f->sql->update('sis_application_intake', ['submitted_term_code' => 'FALSE'], ['id' => $id->value]);
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $upgrade->run();
    }
    public function testOlderAcceptedApplicationRemainsExplicitlyUnknownAfterAnIntakeApproval(): void
    {
        [$f, , $term] = $this->fixture();
        $p = $f->legacyPerson($f->user(), 'Older', 'Fictional');
        $a = $f->legacyApplication($p->id, $f->program, $f->term);
        $f->sql->update('sis_applications', ['status' => 'accepted', 'version' => 1], ['id' => $a->id->value]);
        $this->approve($f, $a->id, $term);
        $current = $f->admissions->application($a->id);
        self::assertFalse($f->decisionLedger()->hasRecordedAcceptance($current));
        self::assertTrue($f->decisionService()->history($a->id)['earlier_history_unknown']);
        self::assertSame([], $f->sql->rows('sis_application_intake', ['id' => $a->id->value]));
        self::assertSame('entry_defer', array_slice($f->decisionService()->history($a->id)['items'], -1)[0]['kind']);
    }
    public function testMigrationAddsOnlyIndependentProtectedAuthorityWithoutGrantingItToRegularAdministrators(): void
    {
        [$f] = $this->fixture();
        $p = $f->sql->one('sis_access_permissions', ['name' => 'sis:admissions:defer']);
        self::assertSame(1, (int) $p['is_system']);
        $edges = $f->sql->rows('sis_access_implications', ['descendant_id' => $p['id']]);
        self::assertCount(1, $edges);
        self::assertSame($p['id'], $edges[0]['ancestor_id']);
        self::assertSame([], $f->sql->rows('sis_access_role_permissions', ['permission_id' => $p['id']]));
        self::assertSame([], $f->sql->rows('sis_access_user_permissions', ['permission_id' => $p['id']]));
    }

    public function testUpgradeOfTheEarlierIntakeSchemaReconstructsOnlyAuthenticatedOriginalFacts(): void
    {
        [$f, $id] = $this->fixture(true);
        $index = $f->sql->rows('sis_application_intake');
        $events = $f->sql->rows('sis_history_events');
        $f->sql->execute('DROP INDEX sai_submitted_term');
        $f->sql->execute('ALTER TABLE sis_application_intake DROP COLUMN submitted_term_id');
        $f->sql->execute('ALTER TABLE sis_application_intake DROP COLUMN submitted_term_code');
        $m = new \CreateSisAdmissionDeferral('20261117000008');
        $m->setObjectMap(new \ArrayObject([
            'phpmig.adapter' => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                $f->connection,
                'test_migrations'
            ),
            'sis.history_codec' => new \Infrastructure\EventSourcing\ProtectedEventCodec(
                \EventStoreFixture::config($f->connection->queryBuilder())
            ),
        ]));
        $m->up();
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        self::assertSame($index, $f->sql->rows('sis_application_intake'));
        self::assertSame($f->term->value, $f->admissions->application($id)->entryTermId->value);
    }
}
