<?php

declare(strict_types=1);

require_once __DIR__ . '/ReusableGroupFixture.php';
require_once __DIR__ . '/ProgramPublicationFixture.php';

use Domain\Shared\ValueObject\EntityId;

final class StudentSpecializationRequirementsFixture
{
    public ReusableGroupFixture $r;
    public ProgramPublicationFixture $program;
    public array $choices = [];
    public array $plans = [];
    public array $versions = [];
    public EntityId $student;
    public EntityId $assignment;
    public \Domain\Student\Service\StudentSpecializationService $service;
    public string $programVersion;

    public function __construct()
    {
        $this->r = $r = new ReusableGroupFixture();
        $s = $r->sis;
        $this->program = new ProgramPublicationFixture($s);
        $references = new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql);
        $choices = new \Infrastructure\Persistence\Repository\SqlProgramSpecializationRepository($s->sql);
        $policy = new \Domain\Catalog\Service\SpecializationPolicyService(
            new \Infrastructure\Persistence\Repository\SqlSpecializationPolicyRepository($s->sql),
            $choices, $references, $s->access, $s->transactions, $s->audit,
        );
        $policy->saveInstitution(0, 2, 1);
        $policy->saveProgram($s->program, 0, 0, 2);
        $setup = new \Domain\Catalog\Service\ProgramSpecializationService(
            $choices, $references, $s->access, $s->transactions, $s->audit,
        );
        foreach (['DATA', 'ART'] as $code) {
            $this->choices[$code] = $setup->save($s->program, null, null, $code, 'Fictional ' . $code, null, true);
        }
        $review = $this->program->service->review($s->program, $r->year, 'Fictional offered choices.');
        $this->programVersion = $this->program->service->publish($s->program, $review['review_token'])['operation_id'];
        foreach ($this->choices as $code => $choice) {
            $this->plans[$code] = $r->degrees->open($s->program, $r->year, new EntityId($choice['id']));
            $definition = $r->definition;
            $definition['name'] = 'Fictional ' . $code . ' requirements';
            $this->plans[$code] = $r->degrees->save(new EntityId($this->plans[$code]['id']), 0,
                new \Domain\DegreeAudit\ValueObject\AuditRequirementsV3($definition));
            $this->versions[$code] = $this->publish($code);
        }
        $person = $s->admissionsService()->createPerson($s->user(), 'Fictional', 'Learner');
        $term = $s->sql->rows('sis_term_calendar', ['academic_year_id' => $r->year->value], 1)[0]['id'];
        $application = $s->legacyApplication($person->id, $s->program, new EntityId($term));
        $s->legacyAdmissionDecision($application->id, true);
        $this->student = $s->matriculate($application->id)->id;
        $this->assignment = new EntityId($s->sql->rows('sis_student_programs', ['student_id' => $this->student->value], 1)[0]['id']);
        $this->service = $this->service();
        $this->select(['DATA']);
    }
    public function service(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\Shared\EventSourcing\HistoricalEventStore $history = null,
    ): \Domain\Student\Service\StudentSpecializationService {
        $s = $this->r->sis;
        $history ??= $this->r->history;
        return new \Domain\Student\Service\StudentSpecializationService(
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($s->sql), $s->admissions,
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($s->sql), $s->programStatusLedger(),
            new \Domain\Catalog\Service\PublishedProgramReader($history, new \Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository($s->sql)),
            new \Infrastructure\Persistence\Repository\SqlStudentSpecializationRepository($s->sql),
            $history, $s->access, $s->transactions, $audit ?? $s->audit, $this->r->catalog->fixture->cipher, $s->clock,
            new \Infrastructure\Persistence\Repository\SqlRequirementsRepository($s->sql),
            new \Domain\DegreeAudit\Service\PublishedRequirementsReader($history),
        );
    }
    public function publish(string $code = 'DATA'): string
    {
        $id = new EntityId($this->plans[$code]['id']);
        $review = $this->r->degrees->review($id, 'Fictional specialization requirements publication.');
        return $this->r->degrees->publish($id, $review['review_token'])['operation_id'];
    }
    public function select(array $codes): array
    {
        $ids = array_map(fn(string $code): string => $this->choices[$code]['id'], $codes);
        $view = $this->service->view($this->student, $this->assignment);
        $review = $this->service->review($this->student, $this->assignment, $view['revision'], $ids, 'Fictional choices review.');
        return $this->service->commit($this->student, $this->assignment, $review['review_token']);
    }
    public function review(string $code = 'DATA', ?string $version = null): array
    {
        $choice = new EntityId($this->choices[$code]['id']);
        $page = $this->service->requirementChoices($this->student, $this->assignment, $choice);
        return $this->service->reviewRequirements($this->student, $this->assignment, $choice, $page['revision'],
            new EntityId($this->plans[$code]['id']), new EntityId($version ?? $this->versions[$code]), 'Fictional protected assignment reason.');
    }
    public function assign(string $code = 'DATA', ?string $version = null): array
    {
        return $this->service->assignRequirements($this->student, $this->assignment,
            new EntityId($this->choices[$code]['id']), $this->review($code, $version)['review_token']);
    }
}
