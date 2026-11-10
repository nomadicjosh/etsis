<?php

declare(strict_types=1);

require_once __DIR__ . "/ReusableGroupFixture.php";
use Domain\Admissions\Service\AdmissionsService;
use Domain\DegreeAudit\Service\PublishedRequirementsReader;
use Domain\DegreeAudit\Service\StudentRequirementsService;
use Domain\DegreeAudit\ValueObject\PursuitStart;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogYearRepository;
use Infrastructure\Persistence\Repository\SqlRequirementsRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use Infrastructure\Persistence\Repository\SqlStudentRequirementsRepository;
final class StudentRequirementsFixture
{
    public ReusableGroupFixture $f;
    public StudentRequirementsService $service;
    public EntityId $student;
    public EntityId $assignment;
    public EntityId $version;
    public function __construct()
    {
        $this->f = new ReusableGroupFixture();
        $f = $this->f;
        $s = $f->sis;
        $f->definition["length_months"] = 48;
        $f->saveDegree();
        $this->version = new EntityId($f->publishDegree()["operation_id"]);
        $term = new EntityId(
            $s->sql->rows("sis_term_calendar", ["academic_year_id" => $f->year->value], 1)[0]["id"],
        );
        $admit = new AdmissionsService(
            $s->admissions,
            $s->access,
            $s->transactions,
            $s->audit,
            new \Infrastructure\Identity\RandomInstitutionalIdGenerator(),
            $s->decisionLedger(),
            $s->submissionService(),
        );
        $person = $admit->createPerson($s->access->actor, "Fictional", "Learner");
        $application = $s->legacyApplication($person->id, $s->program, $term);
        $s->legacyAdmissionDecision($application->id, true);
        $student = $s->legacyStudent($application->id);
        $this->student = $student->id;
        $this->assignment = new EntityId(
            $s->sql->rows("sis_student_programs", ["student_id" => $this->student->value], 1)[0]["id"],
        );
        $this->service = $this->service();
    }
    public function service(?\Domain\Audit\Repository\AuditTrail $audit = null): StudentRequirementsService
    {
        $f = $this->f;
        $s = $f->sis;
        return new StudentRequirementsService(
            new SqlStudentRequirementsRepository($s->sql),
            new SqlStudentRecordRepository($s->sql),
            $s->admissions,
            new SqlCatalogYearRepository($s->sql),
            new SqlRequirementsRepository($s->sql),
            new PublishedRequirementsReader($f->history),
            $f->history,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $f->catalog->fixture->cipher,
            $s->clock,
        );
    }
    public function review(string $start = "2026-01-31"): array
    {
        return $this->service->review(
            $this->student,
            $this->assignment,
            $this->f->degree,
            $this->version,
            new PursuitStart($start),
            "Fictional registrar approval",
        );
    }
    public function assign(): array
    {
        return $this->service->assign($this->student, $this->assignment, $this->review()["review_token"]);
    }
}
