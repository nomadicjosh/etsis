<?php

declare(strict_types=1);

namespace Tests\Support;

// phpcs:disable PSR1.Files.SideEffects -- Explicit isolated fictional fixture dependencies.
require_once __DIR__ . '/StudentRequirementsFixture.php';

use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\Shared\ValueObject\EntityId;

final class StandingProposalFixture
{
    public \StudentRequirementsFixture $requirements;
    public \SisFixture $sis;
    public EntityId $student;
    public EntityId $pursuit;
    public array $definitions;
    public array $rules;

    public function __construct(bool $publish = true)
    {
        $this->requirements = $f = new \StudentRequirementsFixture();
        $this->sis = $s = $f->f->sis;
        $this->student = $f->student;
        $this->pursuit = $f->assignment;
        $s->gradeScale([
            ['code' => 'A', 'min_score_milli' => 90000, 'max_score_milli' => 100000,
                'points_milli' => 4000, 'passes' => true, 'counts_gpa' => true],
            ['code' => 'C', 'min_score_milli' => 70000, 'max_score_milli' => 89999,
                'points_milli' => 2000, 'passes' => true, 'counts_gpa' => true],
            ['code' => 'F', 'min_score_milli' => 0, 'max_score_milli' => 69999,
                'points_milli' => 0, 'passes' => false, 'counts_gpa' => true],
        ]);
        $d = $f->f->definition;
        $d['policies']['repeat_gpa'] = 'all_eligible';
        $d['policies']['repeat_attempt_order'] = 'academic_period';
        $view = $f->f->degrees->view($f->f->degree);
        $f->f->degrees->save($f->f->degree, $view['revision'], new AuditRequirementsV3($d));
        $f->version = new EntityId($f->f->publishDegree()['operation_id']);
        $f->assign();
        $service = $s->standingService();
        $this->definitions = [$service->saveDefinition(null, 'REVIEW', 'Fictional academic review', true, null),
            $service->saveDefinition(null, 'GOOD', 'Fictional good standing', true, null)];
        $this->rules = ['enabled' => true, 'minimum_gpa_credits_milli' => 0, 'bands' => []];
        foreach ($this->definitions as $i => $definition) {
            $this->rules['bands'][] = ['minimum_gpa_milli' => $i * 2000,
                'definition_id' => $definition['id'], 'code' => $definition['code'], 'title' => $definition['title']];
        }
        if ($publish) {
            $this->publish();
        }
    }

    public function publish(?\Domain\Audit\Repository\AuditTrail $audit = null): array
    {
        $service = $this->sis->standingPolicies($audit);
        $review = $service->review($this->rules, $service->view()['version'], 'Fictional standing policy publication.');
        return $service->publish(
            new EntityId($review['publication_id']),
            $review['rules'],
            $review['version'],
            $review['reason'],
            $review['review_token']
        );
    }

    public function work(string $code = 'C'): array
    {
        $s = $this->sis;
        $section = $s->section(faculty: $s->access->actor);
        $enrollment = $s->service->register($this->student, $section->id)->id;
        $ledger = $s->academicCredits();
        $choices = array_column($ledger->choices($this->student, $section->id, $enrollment)['items'], 'id', 'code');
        $review = $ledger->review($this->student, $section->id, $enrollment, new EntityId($choices[$code]), 0);
        $ledger->commit($this->student, $section->id, $review['review_token']);
        return ['section' => $section->id, 'enrollment' => $enrollment, 'grades' => $choices];
    }

    public function row(array $proposal): array
    {
        $band = $proposal['standing'];
        return ['id' => $proposal['proposal']['period_id'], 'definition_id' => $band['definition_id'],
            'code' => $band['code'], 'title' => $band['title'], 'starts_on' => '2026-08-01',
            'ends_on' => null, 'policy_reference' => null];
    }

    public function facts(): array
    {
        $s = $this->sis;
        return [\EventStoreFixture::historyDigest($s->sql), ...array_map($s->sql->rows(...), [
            'sis_history_streams', 'sis_history_operations', 'sis_student_standing',
            'sis_standing_proposal_policy_state', 'sis_academic_credits',
            'sis_enrollments', 'sis_student_accounts', 'sis_account_balances', 'sis_account_entries',
        ])];
    }
}
