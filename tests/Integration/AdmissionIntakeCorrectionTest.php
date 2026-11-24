<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Admissions\Service\ApplicationFormService;
use Domain\Admissions\ValueObject\AdmissionDeferralSchema;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlApplicationFormRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;
use Symfony\Component\Process\Process;
use AcademicCalendarFixture;

final class AdmissionIntakeCorrectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/AcademicCalendarFixture.php';
    }

    public function testIntakeChangesUseActualProvidersPrivateHttpAndIndependentAuthority(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-admission-intake']);
        $process->setTimeout(12);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    private function fixture(): array
    {
        $f = new SisFixture();
        $calendar = new AcademicCalendarFixture($f);
        $calendar->type();
        $terms = [];
        foreach ([2027, 2028] as $year) {
            $y = $calendar->year($year);
            $terms[] = new EntityId($f->sql->rows('sis_term_calendar', ['academic_year_id' => $y['id']], 1)[0]['id']);
        }
        $person = $f->legacyPerson($f->access->actor, 'Fictional', 'Applicant');
        $forms = new ApplicationFormService(
            new SqlApplicationFormRepository($f->sql),
            $f->admissionsService(),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->submissionService()
        );
        $form = $forms->save(null, $f->program, 'Fictional original application', [
            ['key' => 'statement', 'label' => 'Statement', 'type' => 'textarea', 'required' => true],
        ], null);
        $forms->publish($form->id, 0);
        $id = $forms->submit(EntityId::generate(), $form->id, $person->id, $f->term, [
            'statement' => 'Fictional original answer.',
        ]);
        return [$f, $id, ...$terms];
    }

    private function correct(SisFixture $f, EntityId $id, EntityId $term, string $kind = 'correct'): array
    {
        $s = $f->deferralService();
        return $s->commit($id, $s->review($id, $term, $kind, 'Fictional private correction reason.')['review_token']);
    }

    private function request(SisFixture $f, EntityId $id): array
    {
        $s = $f->applicationRevisionService();
        return $s->commit($id, $s->review($id, 'request', [
            'instructions' => 'Please clarify the fictional statement.',
        ], false)['review_token'], false);
    }

    public function testCorrectionRetainsOriginalFactsAndRetriesAfterLaterCorrectionsAcceptanceAndMatriculation(): void
    {
        [$f, $id, $earlier, $later] = $this->fixture();
        $s = $f->deferralService();
        $original = [];
        foreach (['sis_form_submissions', 'sis_application_intake', 'sis_persons'] as $table) {
            $original[$table] = $f->sql->rows($table);
        }
        $events = $f->sql->rows('sis_history_events');
        $decision = $f->decisionService();
        $oldDecision = $decision->review($id, true, 'Fictional acceptance review.');
        $review = $s->review($id, $later, 'correct', 'Fictional private correction reason.');
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        $first = $s->commit($id, $review['review_token']);
        self::assertSame('submitted', $first['status']);
        self::assertSame('correct', $first['kind']);
        $this->correct($f, $id, $earlier); // An earlier future intake is a correction, not a deferment.
        self::assertSame($first, $s->commit($id, $review['review_token']));
        self::assertSame($earlier->value, $f->admissions->application($id)->entryTermId->value);
        $verifier = new \Infrastructure\Projection\SqlApplicationProjectionVerifier(
            $f->sql,
            $f->admissions,
            $f->decisionLedger()
        );
        self::assertSame('verified', $verifier->verify($id)->status);
        try {
            $decision->commit($id, $oldDecision['review_token']);
            self::fail('A decision reviewed against the old intake was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame('submitted', $f->admissions->application($id)->status->value);
        }
        foreach ($original as $table => $rows) {
            self::assertSame($rows, $f->sql->rows($table));
        }
        self::assertSame($events, array_slice($f->sql->rows('sis_history_events'), 0, count($events)));
        self::assertStringNotContainsString(
            'Fictional private correction reason.',
            json_encode($f->sql->rows('sis_history_events'))
        );
        $timeline = $decision->history($id)['items'];
        self::assertSame('entry_correct', $timeline[1]['kind']);
        self::assertSame('submitted', $timeline[1]['status']);
        self::assertArrayNotHasKey('reason', $timeline[1]);
        $decision->commit($id, $decision->review($id, true, 'Fictional final acceptance.')['review_token']);
        $student = $f->matriculate($id); // Optional-offer policy must remain optional after a correction.
        self::assertSame($earlier->value, $student->entryTermId->value);
        self::assertSame($first, $s->commit($id, $review['review_token']));
        self::assertSame('matriculated', $f->admissions->application($id)->status->value);
        self::assertCount(1, $f->sql->rows('sis_applications'));
        self::assertCount(1, $f->sql->rows('sis_students'));
    }

    public function testRequiredOfferPolicyStillAppliesAfterCorrectionAndAcceptance(): void
    {
        [$f, $id, $term] = $this->fixture();
        $this->correct($f, $id, $term);
        $f->admissionPolicyService()->save('offers', true, 0);
        $d = $f->decisionService();
        $d->commit($id, $d->review($id, true, 'Fictional acceptance.')['review_token']);
        $this->expectException(ValidationException::class);
        $f->matriculate($id);
    }

    public function testCorrectionAndDefermentRequireTheirOwnDutiesEvenForTheApplicantOwner(): void
    {
        [$f, $id, $term] = $this->fixture();
        $s = $f->deferralService();
        $f->access->staff = false;
        foreach (
            [[], ['sis:admissions:correct'], ['sis:admissions:manage'],
            ['sis:admissions:manage', 'sis:admissions:defer']] as $grants
        ) {
            $f->access->grants = $grants;
            try {
                $s->review($id, $term, 'correct', 'Fictional reason.');
                self::fail('Owner/general/deferment authority granted an intake correction.');
            } catch (AccessDeniedException) {
                self::assertSame($f->term->value, $f->admissions->application($id)->entryTermId->value);
            }
        }
        $f->access->grants = ['sis:admissions:manage', 'sis:admissions:correct'];
        $review = $s->review($id, $term, 'correct', 'Fictional reason.');
        self::assertSame('submitted', $s->current($id)['application_status']);
        self::assertSame($term->value, $s->choices($id, '27/FA')['items'][0]['id']);
        $s->commit($id, $review['review_token']);
        try {
            $s->review($id, $term, 'defer', 'Fictional reason.');
            self::fail('Correction duty granted deferment authority.');
        } catch (AccessDeniedException) {
            self::assertSame('submitted', $f->admissions->application($id)->status->value);
        }
    }

    public function testPendingAnswerRequestsAndCompletedRequestCyclesInvalidateIntakeReviews(): void
    {
        [$f, $id, $term] = $this->fixture();
        $s = $f->deferralService();
        $old = $s->review($id, $term, 'correct', 'Fictional reason.');
        $this->request($f, $id);
        self::assertTrue($s->current($id)['answers_pending']);
        foreach (['review', 'commit', 'choices'] as $action) {
            try {
                match ($action) {
                    'review' => $s->review($id, $term, 'correct', 'Fictional reason.'),
                    'commit' => $s->commit($id, $old['review_token']),
                    'choices' => $s->choices($id, ''),
                };
                self::fail('An unresolved answer correction was ignored.');
            } catch (ValidationException) {
                self::assertSame($f->term->value, $f->admissions->application($id)->entryTermId->value);
            }
        }
        $revisions = $f->applicationRevisionService();
        $revisions->commit($id, $revisions->review($id, 'cancel', [
            'reason' => 'Fictional request no longer needed.',
        ], false)['review_token'], false);
        self::assertFalse($s->current($id)['answers_pending']);
        $this->expectException(ConcurrentModificationException::class);
        $s->commit($id, $old['review_token']);
    }

    public function testWithdrawalsCalendarChangesAndExpiriesPreventStaleCorrections(): void
    {
        foreach (['withdrawal', 'calendar', 'expired', 'decision'] as $change) {
            [$f, $id, $term] = $this->fixture();
            $s = $f->deferralService();
            $review = $s->review($id, $term, 'correct', 'Fictional reason.');
            if ($change === 'withdrawal') {
                $w = $f->withdrawalService();
                $w->commit($id, $w->review($id, 'request', null, 'Fictional request.', true)['review_token'], true);
            } elseif ($change === 'calendar') {
                $f->sql->update('sis_terms', ['starts_on' => '2027-08-16'], ['id' => $term->value]);
            } elseif ($change === 'expired') {
                $f->clock->time = $f->clock->time->modify('+21 minutes');
            } else {
                $d = $f->decisionService();
                $d->commit($id, $d->review($id, true, 'Fictional acceptance.')['review_token']);
            }
            $events = $f->sql->rows('sis_history_events');
            try {
                $s->commit($id, $review['review_token']);
                self::fail('A stale intake correction committed.');
            } catch (ValidationException | ConcurrentModificationException) {
                self::assertSame($f->term->value, $f->admissions->application($id)->entryTermId->value);
                self::assertSame($events, $f->sql->rows('sis_history_events'));
            }
        }
    }

    public function testAuditFailuresSourceMutationAndLostAuthorityRollBackAllFactsAndInbox(): void
    {
        foreach (['audit', 'permission', 'calendar', 'person', 'answers'] as $failure) {
            [$f, $id, $term] = $this->fixture();
            $before = [];
            foreach (
                ['sis_applications', 'sis_history_events', 'sis_history_streams', 'sis_history_operations',
                'sis_admission_updates', 'sis_application_revisions'] as $table
            ) {
                $before[$table] = $f->sql->rows($table);
            }
            $audit = $this->createMock(AuditTrail::class);
            $change = function () use ($f, $id, $term, $failure): void {
                if ($failure === 'audit') {
                    throw new \RuntimeException('Fictional audit failure.');
                }
                if ($failure === 'permission') {
                    $f->access->staff = false;
                    $f->access->grants = ['sis:admissions:manage', 'sis:admissions:defer'];
                } elseif ($failure === 'calendar') {
                    $f->sql->update('sis_terms', ['starts_on' => '2027-08-16'], ['id' => $term->value]);
                } elseif ($failure === 'person') {
                    $person = $f->admissions->application($id)->personId;
                    $f->sql->update('sis_persons', ['given_name' => 'Changed'], ['id' => $person->value]);
                } else {
                    $this->request($f, $id);
                }
            };
            $audit->expects(self::once())->method('record')->willReturnCallback($change);
            $s = $f->deferralService($audit);
            $review = $s->review($id, $term, 'correct', 'Fictional reason.');
            try {
                $s->commit($id, $review['review_token']);
                self::fail('An unaudited, unauthorized or changed correction committed.');
            } catch (\RuntimeException | AccessDeniedException | ConcurrentModificationException) {
                foreach ($before as $table => $rows) {
                    self::assertSame($rows, $f->sql->rows($table), $table . ': ' . $failure);
                }
            }
        }
    }

    public function testCalendarRecheckRetainsBothDefinitionsAndPreventsMatriculationUsingChangedDates(): void
    {
        [$f, $id, $term] = $this->fixture();
        $this->correct($f, $id, $term);
        $f->sql->update('sis_terms', ['starts_on' => '2027-08-16'], ['id' => $term->value]);
        self::assertTrue($f->deferralService()->current($id)['needs_review']);
        $result = $this->correct($f, $id, $term, 'recheck');
        self::assertSame('2027-08-15', $result['before']['starts_on']);
        self::assertSame('2027-08-16', $result['after']['starts_on']);
        self::assertSame('submitted', $result['status']);
        self::assertFalse($f->deferralService()->current($id)['needs_review']);
        $d = $f->decisionService();
        $d->commit($id, $d->review($id, true, 'Fictional acceptance.')['review_token']);
        $f->sql->update('sis_terms', ['starts_on' => '2027-08-17'], ['id' => $term->value]);
        $this->expectException(ValidationException::class);
        $f->matriculate($id);
    }

    public function testSamePastUnlinkedAndInvalidCalendarTargetsAreNotAcceptedOrOfferedInSearch(): void
    {
        foreach (['same', 'past', 'unlinked', 'outside_year'] as $invalid) {
            [$f, $id, $term] = $this->fixture();
            if ($invalid === 'same') {
                $term = $f->term;
            } elseif ($invalid === 'past') {
                $f->clock->time = $f->clock->time->modify('+3 years');
            } elseif ($invalid === 'unlinked') {
                $f->sql->update('sis_term_calendar', ['academic_year_id' => null], ['id' => $term->value]);
            } else {
                $f->sql->update('sis_terms', ['ends_on' => '2029-12-15'], ['id' => $term->value]);
            }
            self::assertNotContains($term->value, array_column($f->deferralService()->choices($id, '')['items'], 'id'));
            try {
                $this->correct($f, $id, $term);
                self::fail('An invalid correction target committed.');
            } catch (ValidationException) {
                self::assertSame($f->term->value, $f->admissions->application($id)->entryTermId->value);
            }
        }
    }

    public function testCorrectionSchemaRejectsUnchangedTermsAndRecheckWithoutChangedCalendar(): void
    {
        [$f, $id, $term] = $this->fixture();
        $this->correct($f, $id, $term);
        $state = $f->decisionLedger()->entryApproval($f->admissions->application($id));
        foreach (['correct', 'recheck'] as $kind) {
            $invalid = $state;
            $invalid['kind'] = $kind;
            $invalid['before'] = $state['after'];
            try {
                AdmissionDeferralSchema::validate($invalid);
                self::fail('A meaningless correction passed history validation.');
            } catch (CorruptHistoryException) {
                self::assertSame('submitted', $f->admissions->application($id)->status->value);
            }
        }
    }

    public function testOlderSubmittedApplicationGainsOnlyAnHonestCurrentObservationAndActualCorrection(): void
    {
        [$f, , $term] = $this->fixture();
        $person = $f->legacyPerson($f->user(), 'Fictional', 'EarlierApplicant');
        $id = EntityId::generate();
        $f->admissions->addApplication(new \Domain\Admissions\AdmissionApplication(
            $id,
            $person->id,
            $f->program,
            $f->term
        ));
        $this->correct($f, $id, $term);
        $history = $f->decisionService()->history($id);
        self::assertTrue($history['earlier_history_unknown']);
        self::assertSame(['observation', 'entry_correct'], array_column($history['items'], 'kind'));
        self::assertNull($history['items'][0]['effective_at']);
        self::assertSame('submitted', $history['items'][1]['status']);
        self::assertSame([], $f->sql->rows('sis_application_intake', ['id' => $id->value]));
    }

    public function testAnAnswerReviewCannotSurviveAnIntakeCorrectionButFreshResubmissionKeepsItsOriginalBasis(): void
    {
        [$f, $id, $term] = $this->fixture();
        $f->admissionPolicyService()->save('answers', 'before_decision', 0);
        $s = $f->applicationRevisionService();
        $input = ['answers' => ['statement' => 'Fictional corrected answer.'], 'reason' => 'Fictional clarification.'];
        $old = $s->review($id, 'submit', $input, true);
        $this->correct($f, $id, $term);
        try {
            $s->commit($id, $old['review_token'], true);
            self::fail('An answer review survived a changed planned intake.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows('sis_application_revisions'));
        }
        $s->commit($id, $s->review($id, 'submit', $input, true)['review_token'], true);
        $current = $s->current($id, true);
        self::assertSame($term->value, $f->admissions->application($id)->entryTermId->value);
        self::assertSame('Fictional corrected answer.', $current['answers']['statement']);
    }
}
