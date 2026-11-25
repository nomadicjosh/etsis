<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\GraduateRecoveryServices;
use Tests\Support\GraduateRegistrationFixture;
use Tests\Support\WorkflowRecoveryFixture;

/** Ordinary services, fictional records and at most two bounded workers on SQLite or opted-in native storage. */
final class GraduateWorkflowRecoveryTest extends TestCase
{
    private WorkflowRecoveryFixture $recovery;
    private GraduateRegistrationFixture $graduate;
    private GraduateRecoveryServices $commands;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('The isolated process-loss checks require POSIX signals.');
        }
        require_once dirname(__DIR__) . '/Support/WorkflowRecoveryFixture.php';
        require_once dirname(__DIR__) . '/Support/GraduateRegistrationFixture.php';
        require_once dirname(__DIR__) . '/Support/GraduateRecoveryServices.php';
        $this->recovery = new WorkflowRecoveryFixture();
        $this->graduate = new GraduateRegistrationFixture($this->recovery->sis, graduateFee: 1250);
        $this->commands = new GraduateRecoveryServices($this->recovery->sis);
    }

    protected function tearDown(): void
    {
        if (isset($this->recovery)) {
            $this->recovery->close();
        }
    }

    private function approval(string $action): array
    {
        $g = $this->graduate;
        $s = $g->sis;
        $s->access->actor = str_starts_with($action, 'advisor_') ? $g->advisorUser : $g->registrar;
        $service = $s->graduateApprovals();
        return [
            'student' => $g->student->value,
            'section' => $g->target->section->id->value,
            'pursuit' => $g->pursuit->value,
            'review' => $service->review(
                $g->student,
                $g->target->section->id,
                $g->pursuit,
                $action,
                $service->view($g->student, $g->target->section->id, $g->pursuit)['version'],
                'Fictional reviewed graduate decision.',
            ),
        ];
    }

    private function interrupted(string $action, #[\SensitiveParameter] array $command): void
    {
        $r = $this->recovery;
        $before = $r->facts();
        $worker = $r->start($action, $command, 'graduate.decision_staged');
        $r->send($worker, 'go');
        $r->await($worker, 'staged');
        self::assertSame($before, $r->facts(), 'Another connection saw an uncommitted graduate decision.');
        $r->kill($worker);
        self::assertSame($before, $r->facts(), 'Process loss left a partial event, counter, receipt or audit.');
    }

    private function lostReply(string $action, #[\SensitiveParameter] array $command): void
    {
        $r = $this->recovery;
        $worker = $r->start($action, $command, loseReply: true);
        $r->send($worker, 'go');
        $r->await($worker, 'committed');
        $r->kill($worker);
    }

    private function verify(): void
    {
        $g = $this->graduate;
        self::assertSame('verified', $g->sis->graduateVerifier()->policy()->status);
        self::assertSame('verified', $g->sis->graduateVerifier()->approvals($g->pursuit)->status);
    }

    public function testPolicyPublicationIsAtomicAndItsOriginalReceiptSurvivesLaterRulesAndExpiry(): void
    {
        $g = $this->graduate;
        $s = $g->sis;
        $policies = $s->graduatePolicies();
        $view = $policies->view();
        $rules = $view['rules'];
        $rules['minimum_graduate_grade_milli'] = 3000;
        $command = ['review' => $policies->review($rules, $view['version'], 'Fictional retained grade rule.')];
        $this->interrupted('publish_graduate_policy', $command);
        $this->lostReply('publish_graduate_policy', $command);
        $receipt = $this->commands->publish($command);
        self::assertSame($command['review']['version'] + 1, $receipt['version']);
        self::assertSame(3000, $policies->view()['rules']['minimum_graduate_grade_milli']);
        $g->policy(gradeMinimum: 4000);
        $s->clock->time = $s->clock->time->modify('+1 day');
        $before = $this->recovery->facts();
        self::assertSame($receipt, $this->commands->publish($command));
        self::assertSame($before, $this->recovery->facts());
        self::assertSame(4000, $policies->view()['rules']['minimum_graduate_grade_milli']);
        self::assertSame('verified', $s->graduateVerifier()->policy()->status);
    }

    public static function approvalActions(): array
    {
        return ['advisor approval' => ['advisor_approve'], 'registrar override' => ['registrar_override'],
            'advisor revocation' => ['advisor_revoke'], 'registrar revocation' => ['registrar_revoke']];
    }

    #[DataProvider('approvalActions')]
    public function testApprovalAndRevocationDecisionsSurviveProcessLossAndOriginalRetries(string $action): void
    {
        $g = $this->graduate;
        $s = $g->sis;
        if ($action !== 'advisor_approve') {
            $g->decide('advisor_approve');
        }
        if (str_ends_with($action, '_revoke')) {
            $g->decide('registrar_override');
        }
        $command = $this->approval($action);
        $this->interrupted('confirm_graduate_approval', $command);
        $this->lostReply('confirm_graduate_approval', $command);
        $receipt = $this->commands->approve($command);
        self::assertSame($command['review']['version'] + 1, $receipt['version']);
        $actor = $s->access->actor;
        $s->access->actor = $g->registrar;
        $view = $s->graduateApprovals()->view($g->student, $g->target->section->id, $g->pursuit);
        self::assertSame($action !== 'advisor_revoke', $view['advisor_recorded']);
        self::assertSame($action === 'registrar_override', $view['registrar_recorded']);
        $g->policy(gpaMinimum: 4000);
        $s->access->actor = $actor;
        $s->clock->time = $s->clock->time->modify('+1 day');
        $before = $this->recovery->facts();
        self::assertSame($receipt, $this->commands->approve($command));
        self::assertSame($before, $this->recovery->facts());
        $this->verify();
    }

    public static function newApprovals(): array
    {
        return ['advisor approval' => ['advisor_approve'], 'registrar override' => ['registrar_override']];
    }

    #[DataProvider('newApprovals')]
    public function testConcurrentApprovalRetriesShareOneDecisionAndReceipt(string $action): void
    {
        $g = $this->graduate;
        if ($action === 'registrar_override') {
            $g->decide('advisor_approve');
        }
        $r = $this->recovery;
        $command = $this->approval($action);
        $first = $r->start('confirm_graduate_approval', $command, 'graduate.decision_staged');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $second = $r->start('confirm_graduate_approval', $command);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        $r->send($first, 'continue');
        $one = $r->result($first);
        $two = $r->result($second);
        self::assertSame('accepted', $one['state']);
        self::assertSame($one, $two);
        self::assertSame($command['review']['version'] + 1, $one['result']['version']);
        self::assertCount($one['result']['version'], $g->sis->graduateApprovalsLedger()->read(
            $g->pursuit,
            new \Domain\Shared\EventSourcing\HistoryReadBudget(),
        )->decisions());
        $before = $r->facts();
        self::assertSame($one['result'], $this->commands->approve($command));
        self::assertSame($before, $r->facts());
        $this->verify();
    }

    public static function commitOutcomes(): array
    {
        return ['first commits' => [true], 'first rolls back' => [false]];
    }

    #[DataProvider('commitOutcomes')]
    public function testAdvisorRevocationAndRegistrarOverrideSerializeWithoutStaleConsent(bool $commit): void
    {
        $g = $this->graduate;
        $g->decide('advisor_approve');
        $override = $this->approval('registrar_override');
        $revoke = $this->approval('advisor_revoke');
        $r = $this->recovery;
        $first = $r->start('confirm_graduate_approval', $revoke, 'graduate.decision_staged');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $g->sis->access->actor = $g->registrar;
        $second = $r->start('confirm_graduate_approval', $override);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        if ($commit) {
            $r->send($first, 'continue');
            self::assertSame('accepted', $r->result($first)['state']);
        } else {
            $r->kill($first);
        }
        self::assertSame($commit ? 'rejected' : 'accepted', $r->result($second)['state']);
        $view = $g->sis->graduateApprovals()->view($g->student, $g->target->section->id, $g->pursuit);
        self::assertSame(!$commit, $view['advisor_recorded']);
        self::assertSame(!$commit, $view['registrar_recorded']);
        self::assertNull($g->sis->registration->enrollment($g->student, $g->target->section->id));
        $this->verify();
    }

    private function registration(): array
    {
        $g = $this->graduate;
        $g->sis->access->actor = $g->registrar;
        return ['section' => $g->target->section->id->value, 'students' => [$g->student->value],
            'review' => $g->sis->registrationBatches()->review(
                new RegistrationBatchSelection($g->target->section->id, $g->student),
                true,
            )];
    }

    private function register(#[\SensitiveParameter] array $command): array
    {
        return $this->graduate->sis->registrationBatches()->commit(
            new EntityId($command['review']['operation_id']),
            new RegistrationBatchSelection(new EntityId($command['section']), $this->graduate->student),
            $command['review']['revision'],
            $command['review']['review_token'],
            true,
        );
    }

    public function testReviewedGraduateRegistrationRollsBackThenRetriesWithoutRecreatingALaterDrop(): void
    {
        $g = $this->graduate;
        $s = $g->sis;
        $g->decide('advisor_approve');
        $g->decide('registrar_override');
        $command = $this->registration();
        $r = $this->recovery;
        $before = $r->facts();
        $worker = $r->start('confirm_registration', $command, 'registration.created');
        $r->send($worker, 'go');
        $r->await($worker, 'staged');
        self::assertSame($before, $r->facts());
        $r->kill($worker);
        self::assertSame($before, $r->facts());
        $this->lostReply('confirm_registration', $command);
        $receipt = $this->register($command);
        $accepted = $s->registration->enrollment($g->student, $g->target->section->id);
        self::assertNotNull($accepted);
        self::assertSame(1, $s->registration->sectionCount($g->target->section->id));
        self::assertSame(3000, (int) $s->sql->one('sis_academic_credits', [
            'source_id' => $accepted->id->value,
        ])['attempted_milli']);
        self::assertSame(1250, (int) $s->sql->rows('sis_account_balances')[0]['balance_minor']);
        $offering = $s->sql->one('sis_enrollment_section_versions', ['id' => $accepted->id->value]);
        self::assertSame(
            $s->registration->section($g->target->section->id)->publicationVersionId->value,
            $offering['publication_id']
        );
        $review = $s->drops->review($g->student, $g->target->section->id);
        $s->drops->commit(
            $g->student,
            $g->target->section->id,
            new EntityId($review['enrollment_id']),
            $review['version'],
            new EntityId($review['operation_id']),
            $review['revision'],
            $review['effective_on']
        );
        self::assertSame(0, (int) $s->sql->rows('sis_account_balances')[0]['balance_minor']);
        $g->policy(gpaMinimum: 4000);
        $s->clock->time = $s->clock->time->modify('+1 day');
        $before = $r->facts();
        self::assertSame($receipt, $this->register($command));
        self::assertSame($before, $r->facts());
        self::assertSame(0, $s->registration->sectionCount($g->target->section->id));
        $this->verify();
    }

    #[DataProvider('commitOutcomes')]
    public function testRegistrationAndAdvisorRevocationKeepAcceptedCreditSeparateFromFutureConsent(bool $commit): void
    {
        $g = $this->graduate;
        $s = $g->sis;
        $g->decide('advisor_approve');
        $g->decide('registrar_override');
        $revoke = $this->approval('advisor_revoke');
        $command = $this->registration();
        $r = $this->recovery;
        $first = $r->start('confirm_registration', $command, 'registration.created');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $s->access->actor = $g->advisorUser;
        $second = $r->start('confirm_graduate_approval', $revoke);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        if ($commit) {
            $r->send($first, 'continue');
            self::assertSame('accepted', $r->result($first)['state']);
        } else {
            $r->kill($first);
        }
        self::assertSame('accepted', $r->result($second)['state']);
        $s->access->actor = $g->registrar;
        $enrollment = $s->registration->enrollment($g->student, $g->target->section->id);
        if ($commit) {
            self::assertNotNull($enrollment);
            $g->policy(gpaMinimum: 4000);
            $credit = $g->grade($g->target, $enrollment->id, 'A');
            self::assertSame(
                3000,
                (int) $s->sql->one('sis_academic_credits', ['id' => $credit->value])['completed_milli'],
            );
            $before = $r->facts();
            $this->register($command);
            self::assertSame($before, $r->facts());
        } else {
            self::assertNull($enrollment);
            $before = $r->facts();
            $retry = $r->start('confirm_registration', $command);
            $r->send($retry, 'go');
            self::assertSame('rejected', $r->result($retry)['state']);
            self::assertSame($before, $r->facts());
        }
        $this->verify();
    }

    private function rejectedRegistration(#[\SensitiveParameter] array $command): void
    {
        $r = $this->recovery;
        $before = $r->facts();
        $worker = $r->start('confirm_registration', $command);
        $r->send($worker, 'go');
        self::assertSame('rejected', $r->result($worker)['state']);
        self::assertSame($before, $r->facts(), 'A refused graduate registration changed recorded facts.');
    }

    public function testRaisedGpaFloorInvalidatesAnUnacceptedReviewWithoutCreatingCreditOrCharges(): void
    {
        $g = $this->graduate;
        $g->decide('advisor_approve');
        $g->decide('registrar_override');
        $command = $this->registration();
        $g->policy(gpaMinimum: 2001);
        $this->rejectedRegistration($command);
        $review = $this->registration()['review'];
        $checks = array_column($review['students'][0]['readiness'], null, 'code');
        self::assertSame('blocked', $checks['graduate_academic']['status']);
        self::assertNull($g->sis->registration->enrollment($g->student, $g->target->section->id));
        $this->verify();
    }

    public function testEveryCompletedGraduateCourseMinimumIncludesAnEarlierFailureAfterALaterPass(): void
    {
        $g = $this->graduate;
        $s = $g->sis;
        $g->decide('advisor_approve');
        $g->decide('registrar_override');
        $first = $s->service->register($g->student, $g->target->section->id, true);
        $g->grade($g->target, $first->id, 'F');
        $undergraduate = $g->offering($g->undergraduate);
        $work = $s->service->register($g->student, $undergraduate->section->id, true);
        $g->grade($undergraduate, $work->id, 'A');
        $later = $g->offering($g->graduate);
        $g->decide('advisor_approve', $later);
        $g->decide('registrar_override', $later);
        $work = $s->service->register($g->student, $later->section->id, true);
        $g->grade($later, $work->id, 'A');
        $g->policy(gradeMinimum: 2000);
        $g->target = $g->offering($g->graduate);
        $command = $this->registration();
        $checks = array_column($command['review']['students'][0]['readiness'], null, 'code');
        self::assertSame('blocked', $checks['graduate_academic']['status']);
        self::assertStringContainsString('Every completed graduate course', $checks['graduate_academic']['message']);
        $this->rejectedRegistration($command);
        self::assertNull($s->registration->enrollment($g->student, $g->target->section->id));
        $this->verify();
    }
}
