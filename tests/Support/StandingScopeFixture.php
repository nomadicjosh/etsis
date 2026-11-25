<?php

declare(strict_types=1);

namespace Tests\Support;

// phpcs:disable PSR1.Files.SideEffects -- Explicit isolated fictional fixture dependencies.
require_once __DIR__ . '/StandingProposalFixture.php';
require_once __DIR__ . '/ProgramPublicationFixture.php';

use Domain\Catalog\Enum\ClassificationKind;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentProgramStatusLedger;
use Infrastructure\Persistence\Repository\SqlCatalogYearRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;

final class StandingScopeFixture
{
    public StandingProposalFixture $base;
    public \SisFixture $sis;
    public \ProgramPublicationFixture $catalog;
    public EntityId $undergraduate;
    public EntityId $graduate;
    public ?EntityId $programVersion = null;

    public function __construct(bool $assign = true)
    {
        $this->base = new StandingProposalFixture(false);
        $this->sis = $s = $this->base->sis;
        $this->catalog = $p = new \ProgramPublicationFixture($s);
        $this->undergraduate = new EntityId($p->classifications->save(
            ClassificationKind::AcademicLevel,
            null,
            'UG',
            'Fictional undergraduate',
            true,
            null
        )['id']);
        $this->graduate = new EntityId($p->classifications->save(
            ClassificationKind::AcademicLevel,
            null,
            'GR',
            'Fictional graduate',
            true,
            null
        )['id']);
        if ($assign) {
            $this->programVersion = $this->publishProgram($this->undergraduate);
        }
        $this->base->work();
    }

    public function draftLevel(?EntityId $level): void
    {
        $s = $this->sis;
        $row = $s->sql->one('sis_programs', ['id' => $s->program->value]);
        $draft = $this->catalog->service->draft($s->program);
        $this->catalog->service->save(
            $s->program,
            $row['code'],
            $row['title'],
            null,
            null,
            $level,
            null,
            $draft['revision']
        );
    }

    public function publishProgram(?EntityId $level, bool $assign = true): EntityId
    {
        $s = $this->sis;
        $this->draftLevel($level);
        $review = $this->catalog->service->review(
            $s->program,
            $this->base->requirements->f->year,
            'Fictional cohort level publication.'
        );
        $catalog = new EntityId($this->catalog->service->publish($s->program, $review['review_token'])['operation_id']);
        if ($assign) {
            $pursuit = new SqlStudentRecordRepository($s->sql)->assignment($this->base->pursuit);
            $context = $s->programStatusLedger()->cohortContext($pursuit);
            $release = new SqlCatalogYearRepository($s->sql)->release($catalog);
            $s->transactions->run(fn() => $s->programStatusLedger()->changeCohort(
                $pursuit,
                StudentProgramStatusLedger::catalogSnapshot($release),
                EntityId::generate(),
                $s->access->actor,
                'Fictional assigned cohort.',
                $context['stream_version'],
                $context['binding']['version'] ?? -1
            ));
        }
        return $catalog;
    }

    public function override(string $kind, EntityId $target, int $floor = 3000, bool $enabled = true): array
    {
        $row = new \Infrastructure\Persistence\Repository\SqlStandingPolicyTargetRepository($this->sis->sql)
            ->get($kind, $target);
        $rules = $this->base->rules;
        unset($rules['overrides']);
        $rules['enabled'] = $enabled;
        $rules['bands'][1]['minimum_gpa_milli'] = $floor;
        return ['kind' => $kind, 'target' => array_intersect_key($row, array_flip(['id', 'code', 'title'])),
            'rules' => $rules];
    }

    public function publish(array $overrides, bool $defaultEnabled = true): array
    {
        $this->base->rules['overrides'] = $overrides;
        $this->base->rules['enabled'] = $defaultEnabled;
        return $this->base->publish();
    }

    public function propose(): array
    {
        return $this->sis->standingService()->propose($this->base->student, $this->base->pursuit);
    }

    public function accept(array $proposal): array
    {
        $f = $this->base;
        $service = $this->sis->standingService();
        $review = $service->review(
            $f->student,
            $f->pursuit,
            [$f->row($proposal)],
            $service->view($f->student, $f->pursuit)['revision'],
            'Fictional scoped standing decision.',
            proposal: $proposal['proposal']
        );
        $service->commit(
            $f->student,
            $f->pursuit,
            new EntityId($review['operation_id']),
            $review['rows'],
            $review['revision'],
            $review['reason'],
            $review['review_token'],
            proposal: $review['proposal']
        );
        return $review;
    }

    public function facts(): array
    {
        return [$this->base->facts(), ...array_map($this->sis->sql->rows(...), ['sis_programs',
            'sis_catalog_classifications', 'sis_program_catalogs',
            'sis_program_catalog_details', 'sis_student_catalogs',
        ])];
    }
}
