<?php

declare(strict_types=1);

require_once __DIR__ . '/ProgramPublicationFixture.php';

use Domain\Shared\ValueObject\EntityId;

final class StudentSpecializationFixture
{
    public ProgramPublicationFixture $program;
    public SisFixture $sis;
    public \Domain\Shared\EventSourcing\HistoricalEventStore $history;
    public \Domain\Student\Service\StudentSpecializationService $service;
    public EntityId $student;
    public EntityId $assignment;
    public array $year;
    public array $choices = [];
    public array $publication;

    public function __construct(?SisFixture $sis = null)
    {
        $this->program = $p = new ProgramPublicationFixture($sis);
        $this->sis = $f = $p->sis;
        $this->history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($f->connection->queryBuilder())),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        $this->year = $p->calendar->year(2024);
        foreach (['ART', 'DATA', 'MUSIC'] as $code) {
            $this->choices[] = $this->choiceService()->save(
                $f->program, null, null, $code, 'Fictional ' . $code,
                'Original description for ' . $code, true,
            );
        }
        $this->policies()->saveInstitution(0, 3, 1);
        $this->policies()->saveProgram($f->program, 0, 0, 2);
        $this->publication = $this->publish();
        $person = $f->admissionsService()->createPerson($f->user(), 'Fictional', 'Learner');
        $application = $f->legacyApplication($person->id, $f->program, new EntityId($this->year['terms'][0]['id']));
        $f->legacyAdmissionDecision($application->id, true);
        $this->student = $f->matriculate($application->id)->id;
        $this->assignment = new EntityId($f->sql->rows('sis_student_programs', ['student_id' => $this->student->value], 1)[0]['id']);
        $this->service = $this->service();
    }
    public function service(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
        ?\Domain\Shared\EventSourcing\HistoricalEventStore $history = null,
        ?\Domain\Shared\Security\StudentAccess $access = null,
    ): \Domain\Student\Service\StudentSpecializationService {
        $f = $this->sis;
        $history ??= $this->history;
        return new \Domain\Student\Service\StudentSpecializationService(
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($f->sql),
            $f->admissions,
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($f->sql),
            $f->programStatusLedger(),
            new \Domain\Catalog\Service\PublishedProgramReader($history, new \Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository($f->sql)),
            new \Infrastructure\Persistence\Repository\SqlStudentSpecializationRepository($f->sql),
            $history, $access ?? $f->access, $f->transactions, $audit ?? $f->audit, $f->dropCipher, $f->clock,
            new \Infrastructure\Persistence\Repository\SqlRequirementsRepository($f->sql),
            new \Domain\DegreeAudit\Service\PublishedRequirementsReader($history),
        );
    }
    public function policies(): \Domain\Catalog\Service\SpecializationPolicyService
    {
        $f = $this->sis;
        return new \Domain\Catalog\Service\SpecializationPolicyService(
            new \Infrastructure\Persistence\Repository\SqlSpecializationPolicyRepository($f->sql),
            new \Infrastructure\Persistence\Repository\SqlProgramSpecializationRepository($f->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($f->sql),
            $f->access, $f->transactions, $f->audit,
        );
    }
    public function choiceService(): \Domain\Catalog\Service\ProgramSpecializationService
    {
        $f = $this->sis;
        return new \Domain\Catalog\Service\ProgramSpecializationService(
            new \Infrastructure\Persistence\Repository\SqlProgramSpecializationRepository($f->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($f->sql),
            $f->access, $f->transactions, $f->audit,
        );
    }
    public function publish(): array
    {
        $review = $this->program->service->review($this->sis->program, new EntityId($this->year['id']), 'Fictional program approval.');
        return $this->program->service->publish($this->sis->program, $review['review_token']);
    }
    public function review(array $ids = [], ?string $revision = null): array
    {
        return $this->service->review(
            $this->student, $this->assignment,
            $revision ?? $this->service->view($this->student, $this->assignment)['revision'],
            $ids, 'Fictional registrar selection.',
        );
    }
    public function select(array $ids = []): array
    {
        return $this->service->commit($this->student, $this->assignment, $this->review($ids)['review_token']);
    }
}
