<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/StudentSpecializationRequirementsFixture.php';
require_once dirname(__DIR__) . '/Support/DegreeProgressFixture.php';

use Domain\DegreeAudit\Service\DegreeProgressService;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Section;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAcademicInputRepository;
use PHPUnit\Framework\TestCase;

final class CombinedDegreeProgressTest extends TestCase
{
    private function fixture(string $sharing = 'one_component'): StudentSpecializationRequirementsFixture
    {
        $r = new ReusableGroupFixture();
        $r->sis->gradeScale([
            ['code' => 'A','min_score_milli' => 90000,'max_score_milli' => 100000,'points_milli' => 4000,'passes' => true,'counts_gpa' => true],
            ['code' => 'B','min_score_milli' => 80000,'max_score_milli' => 89999,'points_milli' => 3000,'passes' => true,'counts_gpa' => true],
            ['code' => 'F','min_score_milli' => 0,'max_score_milli' => 79999,'points_milli' => 0,'passes' => false,'counts_gpa' => true],
        ]);
        $row = $r->groups->view($r->group);
        $d = CourseGroupDefinitionV2::blank($row['definition']['name'])->definition;
        $d['courses'] = $row['definition']['courses'];
        $d['min_credits_milli'] = 3000;
        $d['courses'] = array_slice($d['courses'], 0, 2);
        $d['rules'] = [['id' => EntityId::generate()->value,'mode' => 'choose','minimum_courses' => 1,'course_ids' => array_column($d['courses'], 'course_id')]];
        $r->groups->save($r->group, $row['revision'], new CourseGroupDefinitionV2($d));
        $r->groupVersion = $r->publishGroup();
        $r->groupDefinition = $d;
        $r->definition['groups'] = [['group_id' => $r->group->value,'version_id' => $r->groupVersion->value]];
        $r->definition['min_credits_milli'] = $r->definition['min_resident_milli'] = 3000;
        $f = new StudentSpecializationRequirementsFixture($r, $sharing);
        $r->saveDegree();
        $version = new EntityId($r->publishDegree()['operation_id']);
        $requirements = $f->requirements();
        $review = $requirements->review(
            $f->student,
            $f->assignment,
            $r->degree,
            $version,
            new \Domain\DegreeAudit\ValueObject\PursuitStart('2026-01-31'),
            'Fictional retained degree selection.'
        );
        $requirements->assign($f->student, $f->assignment, $review['review_token']);
        $f->assign();
        return $f;
    }
    private function service(StudentSpecializationRequirementsFixture $f, int $steps = 100000, ?\Domain\DegreeAudit\Repository\AcademicInputRepository $repository = null): DegreeProgressService
    {
        return DegreeProgressFixture::service(
            $f->r->sis,
            $f->r->history,
            $f->requirements(),
            $f->r->catalog->fixture->cipher,
            $repository,
            $steps,
            $f->service
        );
    }
    private function preview(StudentSpecializationRequirementsFixture $f, ?HistoryReadBudget $budget = null): array
    {
        return $this->service($f)->combined($f->student, $f->assignment, $budget);
    }
    private function work(StudentSpecializationRequirementsFixture $f, int $index, string $grade = 'A'): array
    {
        $s = $f->r->sis;
        $c = $f->r->groupDefinition['courses'][$index];
        $section = new Section(
            EntityId::generate(),
            new EntityId($c['course_id']),
            $s->term,
            $s->access->actor,
            'FICTIONAL.' . $index,
            20,
            true,
            true,
            new Money(0, 'USD')
        );
        $s->registration->addSection($section);
        $bindings = $f->r->catalog->bindings();
        $review = $bindings->review($section->id, new EntityId($c['version_id']), 'Fictional retained offering.');
        $bindings->commit($section->id, $review['review_token']);
        $enrollment = $s->service->register($f->student, $section->id)->id;
        $grades = array_column($s->academicCredits()->choices($f->student, $section->id, $enrollment)['items'], 'id', 'code');
        $review = $s->academicCredits()->review($f->student, $section->id, $enrollment, new EntityId($grades[$grade]), 0);
        $s->academicCredits()->commit($f->student, $section->id, $review['review_token']);
        return [$section,$enrollment,$grades];
    }
    private function snapshot(StudentSpecializationRequirementsFixture $f): array
    {
        $out = [];
        foreach (
            ['sis_history_events','sis_history_streams','sis_history_operations','sis_audit','sis_student_requirements',
            'sis_student_specializations','sis_student_specialization_requirements','sis_academic_credits','sis_student_accounts','sis_enrollments'] as $table
        ) {
            $out[$table] = $f->r->sis->sql->rows($table);
        }
        return $out;
    }
    public function testJointSharingChangesFeasibilityWithoutDoublingOverallCreditsOrCollapsingReusableGroups(): void
    {
        foreach (['one_component' => 'infeasible','multiple_components' => 'feasible'] as $sharing => $expected) {
            $f = $this->fixture($sharing);
            $this->work($f, 0);
            $before = $this->snapshot($f);
            $result = $this->preview($f);
            self::assertSame('review', $result['status']);
            self::assertSame($expected, $result['outcome']);
            self::assertSame($sharing, $result['sharing']);
            self::assertCount(2, $result['components']);
            foreach ($result['components'] as $component) {
                $progress = $component['progress'];
                self::assertSame(1, $progress['current_record_count']);
                self::assertSame(3000, $progress['gpa']['overall']['credits_milli']);
                self::assertSame(4000, $progress['gpa']['overall']['milli']);
                $group = $progress['allocation']['groups'][0];
                self::assertSame($f->r->group->value, $group['id']);
                self::assertSame($f->r->groupVersion->value, $group['version_id']);
                self::assertCount($sharing === 'multiple_components' ? 1 : 0, $group['placements']);
            }
            self::assertSame($before, $this->snapshot($f));
        }
    }
    public function testTheSearchReservesDifferentCoursesForCompetingComponentsAndRefreshesCorrections(): void
    {
        $f = $this->fixture();
        $this->work($f, 0);
        [$section,$enrollment,$grades] = $this->work($f, 1);
        $first = $this->preview($f);
        self::assertSame('feasible', $first['outcome']);
        $placed = [];
        foreach ($first['components'] as $component) {
            $placed[] = $component['progress']['allocation']['groups'][0]['placements'][0]['course_id'];
        }
        self::assertCount(2, array_unique($placed));
        $s = $f->r->sis;
        $review = $s->academicCredits()->review($f->student, $section->id, $enrollment, new EntityId($grades['F']), 1, reason:'Fictional correction.');
        $s->academicCredits()->commit($f->student, $section->id, $review['review_token']);
        self::assertSame('infeasible', $this->preview($f)['outcome']);
        self::assertSame('feasible', $first['outcome']);
    }
    public function testEverySelectedSpecializationNeedsItsOwnAssignedPublicationAndOneInventoryIsCollected(): void
    {
        $f = $this->fixture('multiple_components');
        $f->select(['DATA','ART']);
        self::assertSame(['status' => 'needs_review','issues' => ['specialization_requirements_missing'],'mode' => 'combined_curriculum'], $this->preview($f));
        $f->assign('ART');
        $repository = new class ($f) implements \Domain\DegreeAudit\Repository\AcademicInputRepository {
            public int $calls = 0;public function __construct(private StudentSpecializationRequirementsFixture $f)
            {
            }
            public function inventory(EntityId $student): \Domain\DegreeAudit\ValueObject\AcademicInputInventory
            {
                $this->calls++;
                return new SqlAcademicInputRepository($this->f->r->sis->sql)->inventory($student);
            }
        };
        $result = $this->service($f, repository:$repository)->combined($f->student, $f->assignment);
        self::assertSame(1, $repository->calls);
        self::assertCount(3, $result['components']);
        self::assertSame(['degree','specialization','specialization'], array_column($result['components'], 'kind'));
    }
    public function testPreparationAndJointSearchLimitsNeverExposePartialComponentResults(): void
    {
        $f = $this->fixture();
        $this->work($f, 0);
        $this->work($f, 1);
        self::assertSame(['status' => 'limited','mode' => 'combined_curriculum'], $this->preview($f, new HistoryReadBudget(2, 2000)));
        self::assertSame(['status' => 'limited','mode' => 'combined_curriculum'], $this->service($f, 1)->combined($f->student, $f->assignment));
    }
    public function testReadScopeOriginalActorAndProjectionEvidenceAreRequired(): void
    {
        $f = $this->fixture();
        $s = $f->r->sis;
        $s->access->staff = false;
        $s->access->grants = ['sis:student:read'];
        self::assertSame('review', $this->preview($f)['status']);
        $s->access->grants = ['sis:catalog:manage','sis:degree-audit:assign'];
        try {
            $this->preview($f);
            self::fail('Catalog authority read student progress.');

        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->staff = true;
        try {
            $this->service($f)->combined($s->student()->id, $f->assignment);
            self::fail('Foreign scope read combined progress.');

        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $s->sql->update('sis_student_specialization_requirements', ['reference_json' => '{}'], ['assignment_id' => $f->assignment->value]);
        self::assertSame(['status' => 'inconsistent','mode' => 'combined_curriculum'], $this->preview($f));
    }
    private function adjustments(StudentSpecializationRequirementsFixture $f): \Domain\DegreeAudit\Service\RequirementAdjustmentService
    {
        $s = $f->r->sis;
        return new \Domain\DegreeAudit\Service\RequirementAdjustmentService(
            new \Infrastructure\Persistence\Repository\SqlRequirementAdjustmentRepository($s->sql),
            $f->requirements(),
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($s->sql),
            $f->r->history,
            $s->access,
            $s->transactions,
            $s->audit,
            $f->r->catalog->fixture->cipher,
            $s->clock,
            new \Domain\DegreeAudit\Service\PublishedRequirementsReader($f->r->history),
            new \Infrastructure\Persistence\Repository\SqlReplacementCourseRepository($s->sql),
        );
    }
    private function useGroup(StudentSpecializationRequirementsFixture $f, EntityId $template, EntityId $groupVersion): EntityId
    {
        $row = $f->r->degrees->view($template);
        $d = $row['definition'];
        $d['groups'] = [['group_id' => $f->r->group->value,'version_id' => $groupVersion->value]];
        $f->r->degrees->save($template, $row['revision'], new \Domain\DegreeAudit\ValueObject\AuditRequirementsV3($d));
        $review = $f->r->degrees->review($template, 'Fictional retained group choice.');
        return new EntityId($f->r->degrees->publish($template, $review['review_token'])['operation_id']);
    }
    public function testEachComponentKeepsItsOwnGroupPublicationAndPerCourseGradeFloor(): void
    {
        $f = $this->fixture();
        $row = $f->r->groups->view($f->r->group);
        $d = $row['definition'];
        $d['min_course_grade_points_milli'] = 4000;
        $f->r->groups->save($f->r->group, $row['revision'], new CourseGroupDefinitionV2($d));
        $groupVersion = $f->r->publishGroup();
        $version = $this->useGroup($f, new EntityId($f->plans['DATA']['id']), $groupVersion);
        $f->assign('DATA', $version->value);
        $this->work($f, 0, 'B');
        $this->work($f, 1, 'A');
        $result = $this->preview($f);
        self::assertSame('feasible', $result['outcome']);
        $degree = $result['components'][0]['progress']['allocation']['groups'][0];
        $specialization = $result['components'][1]['progress']['allocation']['groups'][0];
        self::assertSame($f->r->groupVersion->value, $degree['version_id']);
        self::assertSame($groupVersion->value, $specialization['version_id']);
        self::assertSame($f->r->groupDefinition['courses'][0]['course_id'], $degree['placements'][0]['course_id']);
        self::assertSame($f->r->groupDefinition['courses'][1]['course_id'], $specialization['placements'][0]['course_id']);
        self::assertSame(4000, $specialization['minimum_grade_points_milli']);
    }
    public function testPursuitWideWaiversApplyToRequiredCoursesAcrossComponentsWithoutCreatingCredits(): void
    {
        $f = $this->fixture('multiple_components');
        $row = $f->r->groups->view($f->r->group);
        $d = $row['definition'];
        $a = $d['courses'][0]['course_id'];
        $b = $d['courses'][1]['course_id'];
        $d['rules'] = [['id' => EntityId::generate()->value,'mode' => 'all','minimum_courses' => 1,'course_ids' => [$a]],
            ['id' => EntityId::generate()->value,'mode' => 'pool','minimum_courses' => 0,'course_ids' => [$b]]];
        $f->r->groups->save($f->r->group, $row['revision'], new CourseGroupDefinitionV2($d));
        $groupVersion = $f->r->publishGroup();
        $version = $this->useGroup($f, $f->r->degree, $groupVersion);
        $requirements = $f->requirements();$review = $requirements->review(
            $f->student,
            $f->assignment,
            $f->r->degree,
            $version,
            new \Domain\DegreeAudit\ValueObject\PursuitStart('2026-01-31'),
            'Fictional degree assignment.'
        );
        $requirements->assign($f->student, $f->assignment, $review['review_token']);
        $version = $this->useGroup($f, new EntityId($f->plans['DATA']['id']), $groupVersion);
        $f->assign('DATA', $version->value);
        $this->work($f, 1);
        self::assertSame('infeasible', $this->preview($f)['outcome']);
        $adjustments = $this->adjustments($f);$waiver = $adjustments->reviewGrant(
            $f->student,
            $f->assignment,
            ['kind' => 'waiver','course_id' => $a],
            'Fictional required-course waiver.'
        );
        $adjustments->commit($f->student, $f->assignment, $waiver['review_token']);
        $before = $this->snapshot($f);
        $result = $this->preview($f);
        self::assertSame('feasible', $result['outcome']);
        foreach ($result['components'] as $component) {
            $p = $component['progress'];
            self::assertSame([$a], $p['allocation']['groups'][0]['waived_courses']);
            self::assertSame(3000, $p['gpa']['overall']['credits_milli']);
            self::assertSame(3000, $p['allocation']['groups'][0]['required_credits_milli']);
            self::assertSame($b, $p['allocation']['groups'][0]['placements'][0]['course_id']);
        }
        self::assertSame($before, $this->snapshot($f));
    }
    public function testLaterProgramPolicyChangesDoNotMoveSelectionsAndChangedCohortsRequireReview(): void
    {
        $f = $this->fixture();
        $this->work($f, 0);
        $s = $f->r->sis;
        $policy = new \Domain\Catalog\Service\SpecializationPolicyService(
            new \Infrastructure\Persistence\Repository\SqlSpecializationPolicyRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlProgramSpecializationRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
        );
        $policy->saveProgram($s->program, 1, 0, 2, 'multiple_components');
        $r = $f->program->service->review($s->program, $f->r->year, 'Fictional future sharing policy.');
        $next = $f->program->service->publish($s->program, $r['review_token']);
        $result = $this->preview($f);
        self::assertSame('one_component', $result['sharing']);
        self::assertSame('infeasible', $result['outcome']);
        $s->transactions->run(function () use ($s, $f, $next): void {
            $s->sql->lock('sis_students', $f->student->value, 'id');
            $catalogs = new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($s->sql);
            $catalogs->bind($f->assignment, new EntityId($next['operation_id']), $catalogs->binding($f->assignment)['version']);
        });
        self::assertSame(['status' => 'needs_review','issues' => ['cohort_changed'],'mode' => 'combined_curriculum'], $this->preview($f));
    }
    public function testClosedPursuitsRemainReadableButAChangedActorCannotReceiveACapturedReview(): void
    {
        $f = $this->fixture();
        $s = $f->r->sis;
        $s->transactions->run(function () use ($s, $f): void {
            $records = new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository($s->sql);
            $records->lock($f->student);
            $s->programStatusLedger()->change($records->assignment($f->assignment), 'closed', EntityId::generate(), $s->access->actor, 'Fictional closure.');
        });
        self::assertSame('review', $this->preview($f)['status']);
        $repository = new class ($f) implements \Domain\DegreeAudit\Repository\AcademicInputRepository {
            public function __construct(private StudentSpecializationRequirementsFixture $f)
            {
            }
            public function inventory(EntityId $student): \Domain\DegreeAudit\ValueObject\AcademicInputInventory
            {
                $s = $this->f->r->sis;
                $result = new SqlAcademicInputRepository($s->sql)->inventory($student);
                $s->access->actor = $s->user();
                return $result;
            }
        };
        $this->expectException(AccessDeniedException::class);
        $this->service($f, repository:$repository)->combined($f->student, $f->assignment);
    }
}
