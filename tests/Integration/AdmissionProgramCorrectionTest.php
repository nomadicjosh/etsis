<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Service\AdmissionProgramCorrectionService;
use Domain\Admissions\Service\ApplicationFormService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlAdmissionUpdatesRepository;
use Infrastructure\Persistence\Repository\SqlApplicationFormRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AdmissionProgramCorrectionTest extends TestCase
{
    private function fixture(?bool $specificForm = null): array
    {
        require_once dirname(__DIR__) . '/Support/AcademicCalendarFixture.php';
        $f = new SisFixture();
        $cal = new \AcademicCalendarFixture($f);
        $cal->type();
        $year = $cal->year(2027);
        $term = new EntityId($f->sql->rows('sis_term_calendar', ['academic_year_id' => $year['id']], 1)[0]['id']);
        $target = EntityId::generate();
        $f->sql->insert('sis_programs', ['id' => $target->value, 'code' => 'ARTS-UG', 'title' => 'Fictional Arts']);
        $person = $f->legacyPerson($f->access->actor, 'Fictional', 'Applicant');
        $submission = EntityId::generate();
        $forms = new ApplicationFormService(
            new SqlApplicationFormRepository($f->sql),
            $f->admissionsService(),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->submissionService()
        );
        if ($specificForm === null) {
            $id = $submission;
            $f->submissionService()->apply($id, $person->id, $f->program, $term);
            $form = null;
        } else {
            $level = null;
            if (!$specificForm) {
                $level = EntityId::generate();
                $f->sql->insert('sis_catalog_classifications', ['id' => $level->value,
                    'kind' => 'academic_level', 'code' => 'UG', 'title' => 'Fictional undergraduate',
                    'active' => 1]);
                foreach ([$f->program, $target] as $program) {
                    $f->sql->insert('sis_program_details', ['id' => $program->value,
                        'academic_level_id' => $level->value]);
                }
            }
            $form = $forms->save(null, $specificForm ? $f->program : null, 'Fictional original form', [
                ['key' => 'statement', 'label' => 'Statement', 'type' => 'textarea', 'required' => true],
            ], null, $level);
            $forms->publish($form->id, 0);
            $id = $forms->submit(
                $submission,
                $form->id,
                $person->id,
                $term,
                ['statement' => 'Fictional original answer.'],
                $f->program
            );
        }
        return [$f, $id, $target, $person, $term, $forms, $form, $submission];
    }

    private function service(SisFixture $f, ?AuditTrail $audit = null): AdmissionProgramCorrectionService
    {
        return new AdmissionProgramCorrectionService(
            $f->admissions,
            $f->decisionLedger(),
            $f->applicationContext(),
            $f->entryTerms(),
            $f->withdrawalRepository(),
            $f->applicationRevisions(),
            new SqlApplicationFormRepository($f->sql),
            new SqlAdmissionUpdatesRepository($f->sql),
            new SqlAccessRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->dropCipher,
            $f->clock
        );
    }

    public function testOriginalFactsAndRetriesSurviveProgramChangesAcceptanceAndMatriculation(): void
    {
        [$f, $id, $target, $person, $term] = $this->fixture();
        $s = $this->service($f);
        $before = $f->sql->rows('sis_history_events');
        $intake = $f->sql->rows('sis_application_intake');
        $decision = $f->decisionService();
        $oldDecision = $decision->review($id, true, 'Fictional earlier decision review.');
        $token = $s->review($id, $target, 'Fictional private correction reason.')['review_token'];
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        $receipt = $s->commit($id, $token);
        self::assertSame('submitted', $receipt['status']);
        try {
            $decision->commit($id, $oldDecision['review_token']);
            self::fail('An earlier decision review survived a program correction.');
        } catch (ConcurrentModificationException) {
            self::assertSame('submitted', $f->admissions->application($id)->status->value);
        }
        self::assertSame($target->value, $f->admissions->application($id)->programId->value);
        self::assertSame($before, array_slice($f->sql->rows('sis_history_events'), 0, count($before)));
        self::assertSame($intake, $f->sql->rows('sis_application_intake'));
        self::assertSame($id->value, $f->submissionService()->apply($id, $person->id, $f->program, $term)->id->value);
        $timeline = $f->decisionLedger()->timeline($f->admissions->application($id));
        self::assertSame('program_correction', $timeline['items'][1]['kind']);
        $verifier = new \Infrastructure\Projection\SqlApplicationProjectionVerifier(
            $f->sql,
            $f->admissions,
            $f->decisionLedger()
        );
        self::assertSame('verified', $verifier->verify($id)->status);
        $second = $s->review($id, $f->program, 'Fictional second program correction.');
        $s->commit($id, $second['review_token']);
        $decision = $f->decisionService();
        $decision->commit($id, $decision->review($id, true, 'Fictional acceptance.')['review_token']);
        $matriculation = $f->matriculationService();
        $matriculation->commit($id, $matriculation->review($id, 'Fictional matriculation.')['review_token']);
        $events = $f->sql->rows('sis_history_events');
        $f->clock->time = $f->clock->time->modify('+21 minutes');
        self::assertSame($receipt, $s->commit($id, $token));
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        self::assertSame($f->program->value, $f->admissions->application($id)->programId->value);
    }

    public function testGeneralFormAnswersAndOriginalSubmissionReceiptRemainValid(): void
    {
        [$f, $id, $target, $person, $term, $forms, $form, $submission] = $this->fixture(false);
        $s = $this->service($f);
        $original = $f->sql->rows('sis_form_submissions');
        $s->commit($id, $s->review($id, $target, 'Fictional program correction.')['review_token']);
        self::assertSame($original, $f->sql->rows('sis_form_submissions'));
        self::assertSame($id->value, $forms->submit(
            $submission,
            $form->id,
            $person->id,
            $term,
            ['statement' => 'Fictional original answer.'],
            $f->program
        )->value);
        self::assertGreaterThan(0, $f->decisionLedger()->verify($f->admissions->application($id)));
        $decision = $f->decisionService();
        $revisions = $f->applicationRevisionService();
        $request = $revisions->review($id, 'request', ['instructions' => 'Clarify the fictional statement.'], false);
        $revisions->commit($id, $request['review_token'], false);
        $answerReview = $revisions->review($id, 'submit', ['answers' => ['statement' => 'Fictional clarification.'],
            'reason' => 'Fictional revised answer.'], true);
        $revisions->commit($id, $answerReview['review_token'], true);
        self::assertSame('Fictional clarification.', $revisions->current($id, true)['answers']['statement']);
        self::assertSame(
            'Fictional original answer.',
            $revisions->current($id, true, original: true)['answers']['statement']
        );
        $decision->commit($id, $decision->review($id, true, 'Fictional corrected-program acceptance.')['review_token']);
        $student = $f->matriculate($id);
        self::assertSame($target->value, $student->programId->value);
        self::assertSame($target->value, $f->admissions->application($id)->programId->value);
        self::assertSame($original, $f->sql->rows('sis_form_submissions'));
    }

    public function testSharedFormCompatibilityIsRecheckedWhenTheTargetOverridesItsApplicationForm(): void
    {
        [$f, $id, $target, , , $forms] = $this->fixture(false);
        $s = $this->service($f);
        $review = $s->review($id, $target, 'Fictional shared form correction.');
        $override = $forms->save(null, $target, 'Fictional program-specific override', [
            ['key' => 'essay', 'label' => 'Essay', 'type' => 'textarea', 'required' => true],
        ], null);
        $forms->publish($override->id, 0);
        $before = $f->sql->rows('sis_history_events');
        self::assertSame([], $s->choices($id, '')['items']);
        try {
            $s->commit($id, $review['review_token']);
            self::fail('The program-specific override reused earlier answers.');
        } catch (ValidationException) {
            self::assertSame($before, $f->sql->rows('sis_history_events'));
            self::assertSame($f->program->value, $f->admissions->application($id)->programId->value);
        }
    }

    public function testChangedIntakeAndOtherActorsCannotReuseAPendingReview(): void
    {
        foreach (['calendar', 'actor', 'pending', 'started'] as $change) {
            [$f, $id, $target] = $this->fixture();
            $s = $this->service($f);
            $review = $s->review($id, $target, 'Fictional reason.');
            if ($change === 'calendar') {
                $a = $f->admissions->application($id);
                $f->sql->update('sis_terms', ['starts_on' => '2027-08-16'], ['id' => $a->entryTermId->value]);
            } elseif ($change === 'actor') {
                $f->access->actor = $f->user();
            } elseif ($change === 'pending') {
                $request = $f->withdrawalService();
                $pending = $request->review($id, 'request', null, 'Fictional withdrawal request.', true);
                $request->commit($id, $pending['review_token'], true);
            } else {
                $f->clock->time = $f->clock->time->modify('+2 years');
            }
            $before = $f->sql->rows('sis_history_events');
            try {
                $s->commit($id, $review['review_token']);
                self::fail('Changed application context reused a pending review.');
            } catch (ConcurrentModificationException | ValidationException | AccessDeniedException) {
                self::assertSame($before, $f->sql->rows('sis_history_events'));
            }
        }
    }

    public function testProgramSpecificFormRequiresSeparateInstitutionalResolution(): void
    {
        [$f, $id, $target] = $this->fixture(true);
        $s = $this->service($f);
        self::assertTrue($s->current($id)['program_form']);
        $before = $f->sql->rows('sis_history_events');
        try {
            $s->review($id, $target, 'Fictional attempted reuse.');
            self::fail('Program-specific answers were reused.');
        } catch (ValidationException) {
            self::assertSame($before, $f->sql->rows('sis_history_events'));
        }
    }

    public function testChoicesAreBoundedEscapedAndExcludeTheCurrentProgram(): void
    {
        [$f, $id, $target] = $this->fixture();
        for ($i = 0; $i < 22; $i++) {
            $f->sql->insert('sis_programs', ['id' => EntityId::generate()->value,
                'code' => 'F-' . $i, 'title' => 'Fictional choice ' . $i]);
        }
        $s = $this->service($f);
        $page = $s->choices($id, '');
        self::assertCount(20, $page['items']);
        self::assertNotNull($page['next']);
        self::assertCount(3, $s->choices($id, '', new EntityId($page['next']))['items']);
        self::assertNotContains($f->program->value, array_column($page['items'], 'id'));
        self::assertSame([], $s->choices($id, '%')['items']);
        self::assertSame($target->value, $s->choices($id, 'ARTS-UG')['items'][0]['id']);
    }

    public function testExpiryProgramRenameAndCompetingDecisionRejectWithoutPartialWrites(): void
    {
        foreach (['expire', 'rename', 'decision'] as $change) {
            [$f, $id, $target] = $this->fixture();
            $s = $this->service($f);
            $review = $s->review($id, $target, 'Fictional program correction.');
            if ($change === 'expire') {
                $f->clock->time = $f->clock->time->modify('+21 minutes');
            } elseif ($change === 'rename') {
                $f->sql->update('sis_programs', ['title' => 'Fictional renamed program'], ['id' => $target->value]);
            } else {
                $d = $f->decisionService();
                $d->commit($id, $d->review($id, true, 'Fictional competing acceptance.')['review_token']);
            }
            $before = $f->sql->rows('sis_history_events');
            try {
                $s->commit($id, $review['review_token']);
                self::fail('A stale review was accepted.');
            } catch (ConcurrentModificationException | ValidationException) {
                self::assertSame($before, $f->sql->rows('sis_history_events'));
                self::assertSame($f->program->value, $f->admissions->application($id)->programId->value);
            }
        }
    }

    public function testFinalAuditSourceChangesAndAuthorityLossRollBackEverything(): void
    {
        foreach (['source', 'authority', 'audit', 'expiry'] as $change) {
            [$f, $id, $target] = $this->fixture();
            $audit = new class ($f, $target, $change) implements AuditTrail {
                public int $calls = 0;
                public function __construct(private SisFixture $f, private EntityId $target, private string $change)
                {
                }
                public function forSubject(EntityId $subject): array
                {
                    return [];
                }
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    if ($action !== 'admissions.program_corrected') {
                        return;
                    }
                    $this->calls++;
                    if ($this->change === 'source') {
                        $this->f->sql->update(
                            'sis_programs',
                            ['title' => 'Fictional race'],
                            ['id' => $this->target->value]
                        );
                    } elseif ($this->change === 'authority') {
                        $this->f->access->staff = false;
                        $this->f->access->grants = ['sis:admissions:manage'];
                    } elseif ($this->change === 'expiry') {
                        $this->f->clock->time = $this->f->clock->time->modify('+21 minutes');
                    } else {
                        throw new \RuntimeException('Fictional required audit failure.');
                    }
                }
            };
            $s = $this->service($f, $audit);
            $token = $s->review($id, $target, 'Fictional reason.')['review_token'];
            $before = [];
            foreach (
                ['sis_applications', 'sis_history_events', 'sis_history_streams', 'sis_history_operations',
                'sis_admission_updates'] as $table
            ) {
                $before[$table] = $f->sql->rows($table);
            }
            try {
                $s->commit($id, $token);
                self::fail('A final source/authority/audit failure was accepted.');
            } catch (ConcurrentModificationException | AccessDeniedException | \RuntimeException) {
                self::assertSame(1, $audit->calls);
                foreach ($before as $table => $rows) {
                    self::assertSame($rows, $f->sql->rows($table));
                }
            }
        }
    }

    public function testAChangedCurrentProgramWithoutACorrectionFailsHistoryVerification(): void
    {
        [$f, $id, $target] = $this->fixture();
        $s = $this->service($f);
        $s->commit($id, $s->review($id, $target, 'Fictional correct program.')['review_token']);
        $before = $f->sql->rows('sis_history_events');
        $f->sql->update('sis_applications', ['program_id' => $f->program->value], ['id' => $id->value]);
        $verifier = new \Infrastructure\Projection\SqlApplicationProjectionVerifier(
            $f->sql,
            $f->admissions,
            $f->decisionLedger()
        );
        self::assertSame('unverified', $verifier->verify($id)->status);
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $s->current($id);
    }

    public function testOlderUntrackedApplicationIsObservedOnlyAtActualConfirmation(): void
    {
        [$f, $id, $target, $person, $term] = $this->fixture();
        $older = EntityId::generate();
        $f->admissions->addApplication(new AdmissionApplication($older, $person->id, $f->program, $term));
        $s = $this->service($f);
        $before = $f->sql->rows('sis_history_events');
        $review = $s->review($older, $target, 'Fictional current correction; original history unknown.');
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        $s->commit($older, $review['review_token']);
        $history = $f->decisionLedger()->timeline($f->admissions->application($older));
        self::assertSame(['observation', 'program_correction'], array_column($history['items'], 'kind'));
        self::assertNull($history['items'][0]['effective_at']);
    }
}
