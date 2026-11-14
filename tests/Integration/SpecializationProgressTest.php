<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/StudentSpecializationRequirementsFixture.php';
require_once dirname(__DIR__) . '/Support/DegreeProgressFixture.php';

use Domain\DegreeAudit\Repository\AcademicInputRepository;
use Domain\DegreeAudit\Service\DegreeProgressService;
use Domain\DegreeAudit\Service\StudentRequirementsService;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Section;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAcademicInputRepository;
use Infrastructure\Persistence\Repository\SqlCatalogYearRepository;
use Infrastructure\Persistence\Repository\SqlRequirementsRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use Infrastructure\Persistence\Repository\SqlStudentRequirementsRepository;
use PHPUnit\Framework\TestCase;

final class SpecializationProgressTest extends TestCase
{
    private function fixture(): StudentSpecializationRequirementsFixture
    {
        $r = new ReusableGroupFixture();
        $r->sis->gradeScale([
            ['code' => 'A', 'min_score_milli' => 90000, 'max_score_milli' => 100000,
                'points_milli' => 4000, 'passes' => true, 'counts_gpa' => true],
            ['code' => 'F', 'min_score_milli' => 0, 'max_score_milli' => 89999,
                'points_milli' => 0, 'passes' => false, 'counts_gpa' => true],
        ]);
        $r->definition['min_credits_milli'] = 6000;
        $r->definition['min_resident_milli'] = 3000;
        return new StudentSpecializationRequirementsFixture($r);
    }
    private function service(StudentSpecializationRequirementsFixture $f, ?AcademicInputRepository $repository = null): DegreeProgressService
    {
        $s = $f->r->sis;
        $requirements = new StudentRequirementsService(
            new SqlStudentRequirementsRepository($s->sql), new SqlStudentRecordRepository($s->sql), $s->admissions,
            new SqlCatalogYearRepository($s->sql), new SqlRequirementsRepository($s->sql),
            new \Domain\DegreeAudit\Service\PublishedRequirementsReader($f->r->history),
            $f->r->history, $s->access, $s->transactions, $s->audit, $f->r->catalog->fixture->cipher, $s->clock,
        );
        return DegreeProgressFixture::service($s, $f->r->history, $requirements,
            $f->r->catalog->fixture->cipher, $repository, specializations: $f->service);
    }
    private function preview(StudentSpecializationRequirementsFixture $f, ?HistoryReadBudget $budget = null): array
    {
        return $this->service($f)->specialization($f->student, $f->assignment, new EntityId($f->choices['DATA']['id']), $budget);
    }
    private function work(StudentSpecializationRequirementsFixture $f, int $index): array
    {
        $s = $f->r->sis;
        $c = $f->r->groupDefinition['courses'][$index];
        $section = new Section(EntityId::generate(), new EntityId($c['course_id']), $s->term, $s->access->actor,
            'FICTIONAL.' . $index, 20, true, true, new Money(0, 'USD'));
        $s->registration->addSection($section);
        $review = $f->r->catalog->bindings()->review($section->id, new EntityId($c['version_id']), 'Fictional retained offering.');
        $f->r->catalog->bindings()->commit($section->id, $review['review_token']);
        $enrollment = $s->service->register($f->student, $section->id)->id;
        $grades = array_column($s->academicCredits()->choices($f->student, $section->id, $enrollment)['items'], 'id', 'code');
        $review = $s->academicCredits()->review($f->student, $section->id, $enrollment, new EntityId($grades['A']), 0);
        $s->academicCredits()->commit($f->student, $section->id, $review['review_token']);
        return [$section, $enrollment, $grades];
    }
    private function snapshot(StudentSpecializationRequirementsFixture $f): array
    {
        $out = [];
        foreach (['sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_audit',
            'sis_student_requirements', 'sis_student_specializations', 'sis_student_specialization_requirements',
            'sis_academic_credits', 'sis_student_accounts', 'sis_enrollments'] as $table) {
            $out[$table] = $f->r->sis->sql->rows($table);
        }
        return $out;
    }
    public function testMissingAndRemovedAssignmentsReturnNoAcademicInputsAndNeverUseADegreeBaseline(): void
    {
        $f = $this->fixture();
        self::assertSame(['status' => 'needs_selection', 'mode' => 'assigned_specialization'], $this->preview($f));
        $f->assign();
        self::assertSame('review', $this->preview($f)['status']);
        $f->select([]);
        $repo = new class implements AcademicInputRepository {
            public function inventory(EntityId $student): \Domain\DegreeAudit\ValueObject\AcademicInputInventory
            { throw new LogicException('Unassigned requirements must not collect academic work.'); }
        };
        $before = $this->snapshot($f);
        $result = $this->service($f, $repo)->specialization($f->student, $f->assignment, new EntityId($f->choices['DATA']['id']));
        self::assertSame(['status' => 'needs_selection', 'mode' => 'assigned_specialization'], $result);
        self::assertSame($before, $this->snapshot($f));
    }
    public function testRetainedRequirementsAndGradeCorrectionsDriveOneReadOnlyCurrentComparison(): void
    {
        $f = $this->fixture();
        $f->assign();
        [$section, $enrollment, $grades] = $this->work($f, 0);
        $this->work($f, 1);
        $before = $this->snapshot($f);
        $first = $this->preview($f);
        self::assertSame('review', $first['status']);
        self::assertSame('assigned_specialization', $first['mode']);
        self::assertSame('DATA', $first['context']['specialization']['code']);
        self::assertSame($f->versions['DATA'], $first['context']['requirements_version_id']);
        self::assertSame(2, $first['current_record_count']);
        $credits = array_column($first['minimums'], null, 'key')['min_credits_milli'];
        self::assertSame(6000, $credits['actual_minimum_milli']);
        self::assertSame('satisfied', $credits['status']);
        self::assertFalse($credits['adjusted']);
        self::assertSame(4000, $first['gpa']['overall']['milli']);
        self::assertSame($before, $this->snapshot($f));
        $s = $f->r->sis;
        $review = $s->academicCredits()->review($f->student, $section->id, $enrollment, new EntityId($grades['F']), 1, reason: 'Fictional correction.');
        $s->academicCredits()->commit($f->student, $section->id, $review['review_token']);
        $corrected = $this->preview($f);
        $credits = array_column($corrected['minimums'], null, 'key')['min_credits_milli'];
        self::assertSame(3000, $credits['actual_minimum_milli']);
        self::assertSame('below_minimum', $credits['status']);
        self::assertSame(2000, $corrected['gpa']['overall']['milli']);
        self::assertSame(4000, $first['gpa']['overall']['milli']);
        $draft = $f->r->degrees->view(new EntityId($f->plans['DATA']['id']));
        $d = $draft['definition'];
        $d['min_credits_milli'] = 90000;
        $f->r->degrees->save(new EntityId($draft['id']), $draft['revision'], new \Domain\DegreeAudit\ValueObject\AuditRequirementsV3($d));
        $f->publish();
        $s->sql->update('sis_program_specializations', ['title' => 'Later editable title'], ['id' => $f->choices['DATA']['id']]);
        $retained = $this->preview($f);
        self::assertSame($first['context'], $retained['context']);
        self::assertSame(6000, array_column($retained['minimums'], null, 'key')['min_credits_milli']['required_milli']);
        self::assertArrayNotHasKey('reason', $retained);
        self::assertArrayNotHasKey('actor_id', $retained);
    }
    public function testReadAuthorityScopeAndTheOriginalActorAreRecheckedAfterCollection(): void
    {
        $f = $this->fixture();
        $f->assign();
        $s = $f->r->sis;
        $s->access->staff = false;
        $s->access->grants = ['sis:student:read'];
        self::assertSame('review', $this->preview($f)['status']);
        $s->access->grants = ['sis:catalog:manage', 'sis:degree-audit:assign'];
        try { $this->preview($f); self::fail('Catalog authority read private specialization progress.'); }
        catch (AccessDeniedException) { self::assertTrue(true); }
        $s->access->staff = true;
        try {
            $this->service($f)->specialization($s->student()->id, $f->assignment, new EntityId($f->choices['DATA']['id']));
            self::fail('A foreign student scope was accepted.');
        } catch (RecordNotFoundException) { self::assertTrue(true); }
        $repo = new class ($s) implements AcademicInputRepository {
            public function __construct(private SisFixture $s) {}
            public function inventory(EntityId $student): \Domain\DegreeAudit\ValueObject\AcademicInputInventory
            {
                $result = new SqlAcademicInputRepository($this->s->sql)->inventory($student);
                $this->s->access->actor = $this->s->user();
                return $result;
            }
        };
        $this->expectException(AccessDeniedException::class);
        $this->service($f, $repo)->specialization($f->student, $f->assignment, new EntityId($f->choices['DATA']['id']));
    }
    public function testProjectionCorruptionAndSharedReadLimitsNeverReturnPartialProgress(): void
    {
        $f = $this->fixture();
        $f->assign();
        self::assertSame(['status' => 'limited', 'mode' => 'assigned_specialization'], $this->preview($f, new HistoryReadBudget(2, 2000)));
        $row = $f->r->sis->sql->rows('sis_student_specialization_requirements')[0];
        $p = json_decode($row['reference_json'], true);
        $p['requirements_name'] = 'Unverified display name';
        $f->r->sis->sql->update('sis_student_specialization_requirements', ['reference_json' => json_encode($p)], ['id' => $row['id']]);
        $before = $this->snapshot($f);
        self::assertSame(['status' => 'inconsistent', 'mode' => 'assigned_specialization'], $this->preview($f));
        self::assertSame($before, $this->snapshot($f));
    }
    public function testClosedPursuitsRemainReadableAndChangedCohortsRetainTheirEarlierContext(): void
    {
        $f = $this->fixture();
        $f->assign();
        $s = $f->r->sis;
        $r = $f->program->service->review($s->program, $f->r->year, 'Fictional later cohort.');
        $next = $f->program->service->publish($s->program, $r['review_token']);
        $s->transactions->run(function () use ($s, $f, $next): void {
            $records = new SqlStudentRecordRepository($s->sql);
            $records->lock($f->student);
            $catalogs = new SqlCatalogYearRepository($s->sql);
            $catalogs->bind($f->assignment, new EntityId($next['operation_id']), $catalogs->binding($f->assignment)['version']);
            $s->programStatusLedger()->change($records->assignment($f->assignment), 'closed', EntityId::generate(), $s->access->actor, 'Fictional closure.');
        });
        $result = $this->preview($f);
        self::assertSame('review', $result['status']);
        self::assertTrue($result['context']['cohort_changed']);
        self::assertSame($f->versions['DATA'], $result['context']['requirements_version_id']);
    }
}
