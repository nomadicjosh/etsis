<?php

declare(strict_types=1);

namespace Tests\Support;

use SisFixture;
use EventStoreFixture;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\LoadDeterminationLedger;
use Domain\Student\Service\LoadDeterminationService;
use Infrastructure\Persistence\Repository\SqlLoadDeterminationRepository;

/** Fictional retained decisions built through their actual reviewed write paths. */
final class StudentDecisionFixture
{
    public SisFixture $sis;
    public EntityId $student;
    public EntityId $pursuit;
    public EntityId $determination;

    public function __construct(?SisFixture $sis = null)
    {
        require_once __DIR__ . '/SisFixture.php';
        $this->sis = $s = $sis ?? new SisFixture();
        $this->student = $s->student($s->user())->id;
        $this->pursuit = new EntityId($s->sql->rows(
            'sis_student_programs',
            ['student_id' => $this->student->value],
            1,
        )[0]['id']);
        $standing = $s->standingService();
        $definition = $standing->saveDefinition(null, 'REVIEW', 'Fictional retained standing', true, null);
        $r = $standing->review($this->student, $this->pursuit, [[
            'id' => EntityId::generate()->value, 'definition_id' => $definition['id'],
            'code' => $definition['code'], 'title' => $definition['title'],
            'starts_on' => '2026-08-01', 'ends_on' => null, 'policy_reference' => 'Private fictional handbook',
        ]], $standing->view($this->student, $this->pursuit)['revision'], 'Private fictional standing reason');
        $standing->commit(
            $this->student,
            $this->pursuit,
            new EntityId($r['operation_id']),
            $r['rows'],
            $r['revision'],
            $r['reason'],
            $r['review_token']
        );
        $policy = $s->loadPolicies();
        $r = $policy->review(null, ['bands' => [
            ['code' => 'PART', 'label' => 'Fictional part time', 'min_credits_milli' => 0],
            ['code' => 'FULL', 'label' => 'Fictional full time', 'min_credits_milli' => 3000],
        ]], $policy->view()['revision'], 'Fictional approved bands');
        $policy->publish(
            null,
            new EntityId($r['operation_id']),
            $r['definition'],
            $r['revision'],
            $r['reason'],
            $r['review_token']
        );
        $s->service->register($this->student, $s->section()->id);
        $service = $this->loads();
        $r = $service->review($this->student, $this->pursuit, $s->term, 'Private fictional load reason');
        $this->determination = new EntityId($r['operation_id']);
        $service->confirm(
            $this->student,
            $this->pursuit,
            $s->term,
            $this->determination,
            $r['revision'],
            $r['reason'],
            $r['review_token']
        );
    }

    public function loads(): LoadDeterminationService
    {
        $s = $this->sis;
        $history = EventStoreFixture::history($s->connection->queryBuilder());
        return new LoadDeterminationService(
            $s->enrollmentLoad(),
            new SqlLoadDeterminationRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($s->sql),
            $s->admissions,
            new \Infrastructure\Persistence\Repository\SqlEnrollmentLoadRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlEnrollmentLoadPolicyRepository($s->sql),
            $history,
            $s->access,
            new class implements \Domain\Advising\Service\AdvisorProgressAccess {
                public function requireAdvisor(EntityId $student, EntityId $pursuit): EntityId
                {
                    throw new \Domain\Shared\Exception\AccessDeniedException();
                }
            },
            $s->transactions,
            $s->audit,
            $s->clock,
            new \Domain\Shared\Service\ReviewProof($s->dropCipher, $s->clock),
            new LoadDeterminationLedger(new SqlLoadDeterminationRepository($s->sql), $history),
        );
    }

    public function facts(): array
    {
        return array_map($this->sis->sql->rows(...), [
            'sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_student_programs',
            'sis_students', 'sis_persons', 'sis_student_standing', 'sis_load_determinations', 'sis_enrollments',
            'sis_academic_credits', 'sis_student_accounts', 'sis_account_balances', 'sis_account_entries',
            'sis_audit', 'sis_history_checks',
        ]);
    }
}
