<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\ValueObject\AwardChange;
use Infrastructure\Persistence\Repository\SqlAcademicInputRepository;
use Infrastructure\Projection\SqlAcademicProjectionVerifier;
use Infrastructure\Projection\SqlApplicationProjectionVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AcademicRecoveryServices;
use Tests\Support\WorkflowRecoveryFixture;

/** The same bounded real-service/process-loss cases run against fictional SQLite or an opted-in native database. */
final class AcademicAdmissionsRecoveryTest extends TestCase
{
    private WorkflowRecoveryFixture $fixture;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('The isolated process-loss checks require POSIX signals.');
        }
        require_once dirname(__DIR__) . '/Support/WorkflowRecoveryFixture.php';
        require_once dirname(__DIR__) . '/Support/AcademicRecoveryServices.php';
        $this->fixture = new WorkflowRecoveryFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->close();
        }
    }

    private function interrupted(string $action, #[\SensitiveParameter] array $command, string $auditAction): void
    {
        $r = $this->fixture;
        $before = $r->facts();
        $worker = $r->start($action, $command, $auditAction);
        $r->send($worker, 'go');
        $r->await($worker, 'staged');
        self::assertSame($before, $r->facts(), 'Another connection saw an uncommitted decision.');
        $r->kill($worker);
        self::assertSame($before, $r->facts(), 'Process loss left a partial decision, projection or receipt.');
    }

    private function lostReply(string $action, #[\SensitiveParameter] array $command): void
    {
        $r = $this->fixture;
        $worker = $r->start($action, $command, loseReply: true);
        $r->send($worker, 'go');
        $r->await($worker, 'committed');
        $r->kill($worker);
    }

    private function application(): array
    {
        require_once dirname(__DIR__) . '/Support/AcademicCalendarFixture.php';
        $s = $this->fixture->sis;
        $calendar = new \AcademicCalendarFixture($s);
        $calendar->type();
        $year = $calendar->year(2027);
        $term = new EntityId($s->sql->rows('sis_term_calendar', ['academic_year_id' => $year['id']], 1)[0]['id']);
        $target = EntityId::generate();
        $s->sql->insert('sis_programs', ['id' => $target->value, 'code' => 'ARTS-UG', 'title' => 'Fictional Arts']);
        $owner = $s->user();
        $s->sql->update('users', ['email' => 'fictional-applicant@example.invalid'], ['user_id' => $owner->value]);
        $person = $s->admissionsService()->createPerson($owner, 'Fictional', 'Applicant');
        $id = EntityId::generate();
        $s->submissionService()->apply($id, $person->id, $s->program, $term);
        return [$id, $target, $person, $term];
    }

    public static function admissionsStages(): array
    {
        return ['program correction' => ['correct_program'], 'acceptance' => ['admit'],
            'matriculation' => ['matriculate']];
    }

    #[DataProvider('admissionsStages')]
    public function testAdmissionsDecisionsSurviveProcessLossAndOriginalRetries(string $stage): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        [$application, $target, $person, $term] = $this->application();
        $programs = new AcademicRecoveryServices($s)->programs();
        $intake = $s->sql->rows('sis_application_intake');
        $decision = $s->decisionService();
        $stale = $decision->review($application, true, 'Fictional original-program review.');
        $programReview = $programs->review($application, $target, 'Fictional target correction.');
        $programCommand = ['application' => $application->value, 'token' => $programReview['review_token']];
        if ($stage === 'correct_program') {
            $this->interrupted($stage, $programCommand, 'admissions.program_corrected');
            $this->lostReply($stage, $programCommand);
        }
        $corrected = $programs->commit($application, $programCommand['token']);
        self::assertSame($target->value, $s->admissions->application($application)->programId->value);
        self::assertSame($intake, $s->sql->rows('sis_application_intake'));
        self::assertSame(
            $application->value,
            $s->submissionService()->apply($application, $person->id, $s->program, $term)->id->value
        );
        try {
            $decision->commit($application, $stale['review_token']);
            self::fail('A review of the original target survived its correction.');
        } catch (ConcurrentModificationException) {
            self::assertSame('submitted', $s->admissions->application($application)->status->value);
        }
        $acceptance = $decision->review($application, true, 'Fictional corrected-program acceptance.');
        $accept = ['application' => $application->value, 'token' => $acceptance['review_token']];
        if ($stage === 'admit') {
            $this->interrupted($stage, $accept, 'admissions.accepted');
            $this->lostReply($stage, $accept);
        }
        $accepted = $decision->commit($application, $accept['token']);
        $matriculation = $s->matriculationService();
        $matriculate = ['application' => $application->value,
            'token' => $matriculation->review($application, 'Fictional matriculation review.')['review_token']];
        if ($stage === 'matriculate') {
            $this->interrupted($stage, $matriculate, 'student.matriculated');
            $this->lostReply($stage, $matriculate);
        }
        $enrolled = $matriculation->commit($application, $matriculate['token']);
        $student = $s->admissions->student(new EntityId($enrolled['student_id']));
        self::assertSame($person->id->value, $student->personId->value);
        self::assertSame($target->value, $student->programId->value);
        self::assertCount(1, $s->sql->rows('sis_persons'));
        self::assertCount(1, $s->sql->rows('sis_students'));
        self::assertCount(1, $s->sql->rows('sis_student_programs'));
        self::assertSame('verified', new SqlApplicationProjectionVerifier(
            $s->sql,
            $s->admissions,
            $s->decisionLedger()
        )->verify($application)->status);
        $before = $r->facts();
        $s->clock->time = $s->clock->time->modify('+1 hour');
        self::assertSame($corrected, $programs->commit($application, $programCommand['token']));
        self::assertSame($accepted, $decision->commit($application, $accept['token']));
        self::assertSame($enrolled, $matriculation->commit($application, $matriculate['token']));
        self::assertSame($before, $r->facts(), 'Original retries rewound a later decision or duplicated a person.');
    }

    private function graded(): array
    {
        $s = $this->fixture->sis;
        $s->gradeScale([
            ['code' => 'A', 'min_score_milli' => 90000, 'max_score_milli' => 100000,
                'points_milli' => 4000, 'passes' => true, 'counts_gpa' => true],
            ['code' => 'B', 'min_score_milli' => 80000, 'max_score_milli' => 89999,
                'points_milli' => 3000, 'passes' => true, 'counts_gpa' => true],
        ]);
        $s->sql->update(
            'users',
            ['email' => 'fictional-faculty@example.invalid'],
            ['user_id' => $s->access->actor->value]
        );
        $s->admissionsService()->createPerson($s->access->actor, 'Fictional', 'Faculty');
        $student = $s->student($s->user());
        $section = $s->section(10, 1250, faculty: $s->access->actor);
        $publication = $this->fixture->publishOffering($section);
        $enrollment = $s->service->register($student->id, $section->id, true);
        $ledger = $s->academicCredits();
        $grades = array_column($ledger->choices($student->id, $section->id, $enrollment->id)['items'], 'id', 'code');
        $review = $ledger->review($student->id, $section->id, $enrollment->id, new EntityId($grades['B']), 0);
        $ledger->commit($student->id, $section->id, $review['review_token']);
        return [$student, $section, $enrollment, $grades, $publication];
    }

    public static function correctionKinds(): array
    {
        return ['grade' => [null], 'grade and academic units' => [4500]];
    }

    #[DataProvider('correctionKinds')]
    public function testIndependentGradeCorrectionsRemainAtomicAndAcceptedRepliesStayOriginal(?int $units): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        [$student, $section, $enrollment, $grades, $publication] = $this->graded();
        $ledger = $s->academicCredits();
        $financial = $this->financialFacts();
        $ledger->saveGradeChangePolicy('approval', 0);
        $grade = $ledger->review(
            $student->id,
            $section->id,
            $enrollment->id,
            new EntityId($grades['A']),
            1,
            reason: 'Fictional corrected assessment and credits.',
            attemptedMilli: $units
        );
        $creditBefore = $s->sql->rows('sis_academic_credits');
        $submitted = $ledger->commit($student->id, $section->id, $grade['review_token']);
        $request = new EntityId($submitted['operation_id']);
        self::assertSame($creditBefore, $s->sql->rows('sis_academic_credits'));
        $s->access->actor = $s->user();
        $review = $ledger->reviewGradeChange($request, 'approved', 'Fictional independent registrar approval.');
        $command = ['request' => $request->value, 'token' => $review['review_token']];
        $this->interrupted('resolve_grade', $command, 'academic.grade_change_approved');
        self::assertSame('pending', $ledger->gradeChangeRequest($request)['status']);
        $this->lostReply('resolve_grade', $command);
        $receipt = $ledger->resolveGradeChange($request, $command['token']);
        $credit = $s->sql->rows('sis_academic_credits')[0];
        self::assertSame($grades['A'], $credit['grade_id']);
        self::assertSame($units ?? 3000, (int) $credit['attempted_milli']);
        self::assertSame(($units ?? 3000) * 4, (int) $credit['grade_points_milli']);
        self::assertSame('approved', $ledger->gradeChangeRequest($request)['status']);
        self::assertSame($financial, $this->financialFacts());
        $registered = $s->transactions->run(function () use ($s, $ledger, $student, $enrollment): array {
            $s->registration->lockStudent($student->id);
            return $ledger->registrationLoadFacts(
                $enrollment,
                new \Domain\Shared\EventSourcing\HistoryReadBudget(maximumEvents: 200)
            );
        });
        self::assertSame(3000, $registered['credits_milli']);
        self::assertSame(
            $publication['section'],
            $s->sql->one('sis_enrollment_section_versions', ['id' => $enrollment->id->value])['publication_id']
        );
        $later = $ledger->review(
            $student->id,
            $section->id,
            $enrollment->id,
            new EntityId($grades['B']),
            (int) $credit['version'],
            reason: 'Fictional later assessment.'
        );
        $ledger->commit($student->id, $section->id, $later['review_token']);
        $before = $r->facts();
        $s->clock->time = $s->clock->time->modify('+1 hour');
        self::assertSame($receipt, $ledger->resolveGradeChange($request, $command['token']));
        self::assertSame($before, $r->facts());
        self::assertSame('verified', new SqlAcademicProjectionVerifier(
            $s->sql,
            new SqlAcademicInputRepository($s->sql),
            $s->inputVerifier()
        )->credit(new EntityId($credit['id']))->status);
    }

    public function testTransferCorrectionsAndVoidsPreserveOriginalReceiptsAcrossProcessLoss(): void
    {
        require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';
        $r = $this->fixture;
        $s = $r->sis;
        $t = new \TransferAwardFixture($s, $s->dropCipher);
        $service = new AcademicRecoveryServices($s)->transfers();
        $change = new AwardChange('amend', 4500, 'B', 'Fictional accepted transfer correction.');
        $review = $service->preview($t->student, $t->award, $change);
        $command = ['student' => $t->student->value, 'award' => $t->award->value, 'review' => $review,
            'change' => ['kind' => 'amend', 'credits' => 4500, 'grade' => 'B', 'reason' => $change->reason]];
        $this->interrupted('correct_transfer', $command, 'transfer.award_amend');
        $this->lostReply('correct_transfer', $command);
        $receipt = $service->confirm(
            $t->student,
            $t->award,
            new EntityId($review['operation_id']),
            $change,
            $review['revision'],
            $review['review_token']
        );
        $credit = $s->sql->one('sis_academic_credits', ['id' => $t->credit->value]);
        self::assertSame(4500, (int) $credit['completed_milli']);
        self::assertSame(13500, (int) $credit['grade_points_milli']);
        self::assertSame(
            $t->courseVersion->value,
            $s->sql->one('sis_credit_course_versions', ['id' => $t->credit->value])['course_version_id']
        );
        $void = new AwardChange('void', null, null, 'Fictional subsequent void.');
        $later = $service->preview($t->student, $t->award, $void);
        $service->confirm(
            $t->student,
            $t->award,
            new EntityId($later['operation_id']),
            $void,
            $later['revision'],
            $later['review_token']
        );
        $before = $r->facts();
        $s->clock->time = $s->clock->time->modify('+1 hour');
        self::assertSame($receipt, $service->confirm(
            $t->student,
            $t->award,
            new EntityId($review['operation_id']),
            $change,
            $review['revision'],
            $review['review_token']
        ));
        self::assertSame($before, $r->facts());
        self::assertSame('V', $s->sql->one('sis_academic_credits', ['id' => $t->credit->value])['status']);
        self::assertSame('verified', new SqlAcademicProjectionVerifier(
            $s->sql,
            new SqlAcademicInputRepository($s->sql),
            $s->inputVerifier()
        )->credit($t->credit)->status);
    }
    private function financialFacts(): array
    {
        return array_intersect_key($this->fixture->facts(), array_flip([
            'sis_account_entries', 'sis_account_entry_heads', 'sis_student_accounts', 'sis_account_balances',
        ]));
    }

    public static function firstApprovalOutcomes(): array
    {
        return ['accepted first approval' => [true], 'terminated first approval' => [false]];
    }

    #[DataProvider('firstApprovalOutcomes')]
    public function testCompetingIndependentApprovalsCannotApplyTheCorrectionTwice(bool $firstCommits): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        [$student, $section, $enrollment, $grades] = $this->graded();
        $ledger = $s->academicCredits();
        $ledger->saveGradeChangePolicy('approval', 0);
        $requestReview = $ledger->review(
            $student->id,
            $section->id,
            $enrollment->id,
            new EntityId($grades['A']),
            1,
            reason: 'Fictional correction for independent review.'
        );
        $submitted = $ledger->commit($student->id, $section->id, $requestReview['review_token']);
        $request = new EntityId($submitted['operation_id']);
        $s->access->actor = $firstActor = $s->user();
        $firstReview = $ledger->reviewGradeChange($request, 'approved', 'Fictional first independent approver.');
        $s->access->actor = $otherActor = $s->user();
        $otherReview = $ledger->reviewGradeChange($request, 'approved', 'Fictional other independent approver.');
        $command = static fn (array $review): array => [
            'request' => $request->value, 'token' => $review['review_token'],
        ];
        $before = $r->facts();
        $financial = $this->financialFacts();
        $s->access->actor = $firstActor;
        $first = $r->start('resolve_grade', $command($firstReview), 'academic.grade_change_approved');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        self::assertSame($before, $r->facts());
        $s->access->actor = $otherActor;
        $second = $r->start('resolve_grade', $command($otherReview));
        $r->send($second, 'go');
        $r->await($second, 'running');
        if ($firstCommits) {
            $r->send($first, 'continue');
            self::assertSame('accepted', $r->result($first)['state']);
        } else {
            $r->kill($first);
        }
        $secondResult = $r->result($second);
        self::assertSame($firstCommits ? 'rejected' : 'accepted', $secondResult['state']);
        $credit = $s->sql->rows('sis_academic_credits')[0];
        self::assertSame($grades['A'], $credit['grade_id']);
        self::assertSame(2, (int) $credit['version']);
        self::assertSame('approved', $ledger->gradeChangeRequest($request)['status']);
        self::assertSame($financial, $this->financialFacts());
        $accepted = $r->facts();
        $s->access->actor = $firstCommits ? $firstActor : $otherActor;
        $chosen = $firstCommits ? $firstReview : $otherReview;
        $ledger->resolveGradeChange($request, $chosen['review_token']);
        self::assertSame($accepted, $r->facts());
    }

    public function testConcurrentMatriculationRetriesCreateOneCanonicalStudentAndPursuit(): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        [$application, , $person] = $this->application();
        $decision = $s->decisionService();
        $decision->commit($application, $decision->review($application, true, 'Fictional admission.')['review_token']);
        $matriculation = $s->matriculationService();
        $review = $matriculation->review($application, 'Fictional concurrent matriculation.');
        $command = ['application' => $application->value, 'token' => $review['review_token']];
        $before = $r->facts();
        $first = $r->start('matriculate', $command, 'student.matriculated');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        self::assertSame($before, $r->facts());
        $second = $r->start('matriculate', $command);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->send($first, 'continue');
        $one = $r->result($first);
        $two = $r->result($second);
        self::assertSame('accepted', $one['state']);
        self::assertSame($one, $two);
        self::assertCount(1, $s->sql->rows('sis_persons'));
        self::assertCount(1, $s->sql->rows('sis_students'));
        self::assertCount(1, $s->sql->rows('sis_student_programs'));
        self::assertSame(
            $person->id->value,
            $s->admissions->student(new EntityId($one['result']['student_id']))->personId->value
        );
        $after = $r->facts();
        self::assertSame($one['result'], $matriculation->commit($application, $command['token']));
        self::assertSame($after, $r->facts());
    }
}
