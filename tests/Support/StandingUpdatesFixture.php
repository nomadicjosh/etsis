<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StandingUpdatesService;
use Domain\Student\Service\StudentStandingService;
use Domain\Student\Student;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlStandingUpdatesRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use SisFixture;

final class StandingUpdatesFixture
{
    public SisFixture $sis;
    public Student $student;
    public EntityId $pursuit;
    public EntityId $owner;
    public EntityId $staff;
    public StudentStandingService $service;
    public array $definition;
    public array $row;

    public function __construct()
    {
        require_once __DIR__ . '/SisFixture.php';

        $this->sis = $s = new SisFixture();
        $this->staff = $s->access->actor;
        $this->student = $s->student($s->user());
        $this->owner = $s->admissions->person($this->student->personId)->ownerUserId;
        $this->pursuit = new EntityId($s->sql->rows('sis_student_programs', [
            'student_id' => $this->student->id->value,
        ], 1)[0]['id']);
        $this->service = $s->standingService();
        $this->definition = $this->service->saveDefinition(null, 'GOOD', 'Fictional good standing', true, null);
        $this->row = ['id' => EntityId::generate()->value, 'definition_id' => $this->definition['id'],
            'code' => 'GOOD', 'title' => 'Fictional good standing', 'starts_on' => '2026-08-01',
            'ends_on' => null, 'policy_reference' => 'Fictional private policy evidence'];
    }

    public function review(?array $rows = null): array
    {
        return $this->service->review(
            $this->student->id,
            $this->pursuit,
            $rows ?? [$this->row],
            $this->service->view($this->student->id, $this->pursuit)['revision'],
            'Fictional private registrar reason.'
        );
    }

    public function commit(array $review, ?AuditTrail $audit = null): array
    {
        return ($audit === null ? $this->service : $this->sis->standingService($audit))->commit(
            $this->student->id,
            $this->pursuit,
            new EntityId($review['operation_id']),
            $review['rows'],
            $review['revision'],
            $review['reason'],
            $review['review_token'],
            $review['historical_evidence'] ?? false,
        );
    }

    public function updates(?AuditTrail $audit = null): StandingUpdatesService
    {
        $s = $this->sis;
        return new StandingUpdatesService(
            new SqlStandingUpdatesRepository($s->sql),
            new SqlStudentRecordRepository($s->sql),
            $s->admissions,
            new SqlPersonAccountRepository($s->sql, $s->admissions, new RandomInstitutionalIdGenerator()),
            $this->service,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
        );
    }

    public function owner(): void
    {
        $this->sis->access->actor = $this->owner;
        $this->sis->access->staff = false;
    }

    public function staff(): void
    {
        $this->sis->access->actor = $this->staff;
        $this->sis->access->staff = true;
    }

    public function snapshot(): array
    {
        return array_map($this->sis->sql->rows(...), ['sis_history_events', 'sis_audit', 'sis_student_standing',
            'sis_standing_updates', 'sis_persons', 'sis_students']);
    }
}
