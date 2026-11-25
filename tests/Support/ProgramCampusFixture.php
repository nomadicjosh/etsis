<?php

declare(strict_types=1);

namespace Tests\Support;

// phpcs:disable PSR1.Files.SideEffects -- Loads isolated fictional fixture dependencies.
require_once __DIR__ . '/ProgramPublicationFixture.php';
require_once __DIR__ . '/OfferingCreditFixture.php';

use Domain\Catalog\Enum\ClassificationKind;
use Domain\Registration\Service\SectionClassificationService;
use Domain\Registration\Service\SectionProgramPolicyService;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentProgramStatusLedger;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCatalogYearRepository;
use Infrastructure\Persistence\Repository\SqlClassificationRepository;
use Infrastructure\Persistence\Repository\SqlSectionClassificationRepository;
use Infrastructure\Persistence\Repository\SqlSectionProgramPolicyRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;

final class ProgramCampusFixture
{
    public \ProgramPublicationFixture $program;
    public \SisFixture $sis;
    public OfferingCreditFixture $target;
    public EntityId $student;
    public EntityId $pursuit;
    public EntityId $year;
    public EntityId $campus;
    public EntityId $otherCampus;
    public EntityId $programVersion;
    public ?EntityId $sectionVersion = null;

    public function __construct(bool $additional = false, bool $publish = true, bool $cohort = true)
    {
        $this->program = $p = new \ProgramPublicationFixture();
        $this->sis = $s = $p->sis;
        $this->year = new EntityId($p->calendar->year(2024)['id']);
        $this->campus = new EntityId($p->classifications->save(
            ClassificationKind::Campus,
            null,
            'MAIN',
            'Fictional Main',
            true,
            null
        )['id']);
        $this->otherCampus = new EntityId($p->classifications->save(
            ClassificationKind::Campus,
            null,
            'EAST',
            'Fictional East',
            true,
            null
        )['id']);
        $this->programVersion = $this->publishProgram(
            $additional ? $this->otherCampus : $this->campus,
            $additional ? [$this->campus] : []
        );
        $student = $s->student($s->user());
        $this->student = $student->id;
        $assignment = $s->sql->rows('sis_student_programs', ['student_id' => $student->id->value], 1)[0];
        $this->pursuit = new EntityId($assignment['id']);
        if ($cohort) {
            $this->assignCohort($this->programVersion);
        }
        $this->target = new OfferingCreditFixture($s, 1250);
        $this->setCampus($this->campus);
        $this->policy(true);
        if ($publish) {
            $this->target->bind();
            $this->sectionVersion = $this->target->publish();
            $this->target->select($this->sectionVersion);
        }
    }

    public function setCampus(?EntityId $campus): void
    {
        $s = $this->sis;
        $references = new SqlClassificationRepository($s->sql);
        $classifications = new SectionClassificationService(
            new SqlSectionClassificationRepository($s->sql, $references),
            $references,
            new SqlCatalogRevisionRepository($s->sql),
            $s->access,
            $s->transactions,
            $s->audit
        );
        $classifications->assign(
            $this->target->section->id,
            null,
            $campus,
            null,
            $classifications->show($this->target->section->id)['revision']
        );
    }

    public function policies(?\Domain\Audit\Repository\AuditTrail $audit = null): SectionProgramPolicyService
    {
        $s = $this->sis;
        return new SectionProgramPolicyService(
            new SqlSectionProgramPolicyRepository($s->sql),
            new SqlCatalogRevisionRepository($s->sql),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit
        );
    }

    public function policy(bool $required): void
    {
        $service = $this->policies();
        $revision = $service->current($this->target->section->id)['revision'];
        $service->save($this->target->section->id, [], $revision, $required);
    }

    public function publishProgram(?EntityId $primary, array $additional = []): EntityId
    {
        $p = $this->program;
        $s = $p->sis;
        $draft = $p->service->draft($s->program);
        $p->service->save(
            $s->program,
            'ARTS.UG',
            'Fictional Arts',
            null,
            null,
            null,
            null,
            $draft['revision'],
            campus: $primary,
            additionalCampuses: $additional
        );
        return new EntityId($p->service->publish($s->program, $p->service->review(
            $s->program,
            $this->year,
            'Fictional campus publication.'
        )['review_token'])['operation_id']);
    }

    public function assignCohort(EntityId $catalog): void
    {
        $s = $this->sis;
        $pursuit = new SqlStudentRecordRepository($s->sql)->assignment($this->pursuit);
        $context = $s->programStatusLedger()->cohortContext($pursuit);
        $release = new SqlCatalogYearRepository($s->sql)->release($catalog);
        $s->transactions->run(fn() => $s->programStatusLedger()->changeCohort(
            $pursuit,
            StudentProgramStatusLedger::catalogSnapshot($release),
            EntityId::generate(),
            $s->access->actor,
            'Fictional cohort change.',
            $context['stream_version'],
            $context['binding']['version'] ?? -1
        ));
    }

    /** A genuine earlier publication shape, created only in this isolated fictional event store. */
    public function historicalProgram(bool $primaryRecorded): EntityId
    {
        $s = $this->sis;
        $review = $this->program->service->review($s->program, $this->year, 'Fictional earlier campus publication.');
        $values = $review['definition'];
        unset($values['classifications']['additional_campuses']);
        if (!$primaryRecorded) {
            unset($values['classifications']['campus']);
        }
        $operation = new EntityId($review['operation_id']);
        $payload = ['version_id' => $operation->value] + array_intersect_key($review, array_flip([
            'previous_version_id', 'academic_year_id', 'academic_year_code', 'catalog_revision', 'previous_catalog_id',
            'specializations',
        ])) + ['definition' => $values];
        $event = \Domain\Catalog\Event\ProgramDefinitionWasPublished::occur(
            new \Domain\Catalog\ValueObject\ProgramPublicationId($s->program->value),
            $payload,
            ['__aggregate_type' => 'program_publication', 'schema_version' => 1]
        )->withPlayhead(2);
        $store = \EventStoreFixture::history($s->connection->queryBuilder());
        $s->transactions->run(function () use ($s, $store, $event, $operation, $values, $review): void {
            $store->append(
                new \Domain\Shared\EventSourcing\StreamReference($event->aggregateId(), 'program_publication'),
                $operation,
                1,
                new \Domain\Shared\EventSourcing\EventContext(
                    $s->access->actor,
                    $s->clock->now(),
                    $operation,
                    reason: 'Fictional earlier campus publication.'
                ),
                [$event]
            );
            $s->sql->update('sis_program_catalogs', ['is_current' => 0], ['program_id' => $s->program->value]);
            $s->sql->insert('sis_program_catalogs', ['id' => $operation->value, 'program_id' => $s->program->value,
                'year_id' => $this->year->value, 'revision' => $review['catalog_revision'], 'published' => 1,
                'is_current' => 1, 'program_code' => $values['code'], 'program_title' => $values['title']]);
            new \Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository($s->sql)->retain(
                $s->program,
                $operation,
                $review['academic_year_code'],
                $review['catalog_revision'],
                \Domain\Catalog\ValueObject\ProgramDefinition::fromArray($values),
                new \Domain\Catalog\ValueObject\ProgramSpecializationSnapshot($review['specializations'])
            );
        });
        return $operation;
    }

    public function facts(): array
    {
        $s = $this->sis;
        return [\EventStoreFixture::historyDigest($s->sql), ...array_map($s->sql->rows(...), [
            'sis_history_streams', 'sis_history_operations', 'sis_enrollments', 'sis_academic_credits',
            'sis_student_accounts', 'sis_account_balances', 'sis_account_entries', 'sis_registration_batches',
            'sis_student_catalogs', 'sis_section_program_policies',
        ])];
    }
}
