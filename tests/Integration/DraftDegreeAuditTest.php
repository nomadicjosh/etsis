<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/StudentRequirementsFixture.php';

use Domain\Catalog\Service\PublishedCourseReader;
use Domain\DegreeAudit\Service\AcademicInputService;
use Domain\DegreeAudit\Service\AcademicPolicyEvaluator;
use Domain\DegreeAudit\Service\AllocationGapAnalyzer;
use Domain\DegreeAudit\Service\CourseAllocationPlanner;
use Domain\DegreeAudit\Service\CourseMatchEvaluator;
use Domain\DegreeAudit\Service\DegreeMinimumEvaluator;
use Domain\DegreeAudit\Service\DegreeProgressEvaluator;
use Domain\DegreeAudit\Service\DegreeWorkReview;
use Domain\DegreeAudit\Service\DegreeAuditTrialService;
use Domain\DegreeAudit\Service\GroupAllocationEvaluator;
use Domain\DegreeAudit\Service\PublishedRequirementsReader;
use Domain\DegreeAudit\Service\RetainedAttemptReader;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Section;
use Domain\Registration\Service\RetainedSectionReader;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAcademicInputRepository;
use Infrastructure\Persistence\Repository\SqlCourseGroupRepository;
use Infrastructure\Persistence\Repository\SqlRequirementsRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;

final class DraftDegreeAuditTest extends TestCase
{
    private function service(StudentRequirementsFixture $f): DegreeAuditTrialService
    {
        $s = $f->f->sis;
        $students = new SqlStudentRecordRepository($s->sql);
        return new DegreeAuditTrialService(
            new SqlRequirementsRepository($s->sql),
            new SqlCourseGroupRepository($s->sql),
            new PublishedRequirementsReader($f->f->history),
            new PublishedCourseReader($f->f->history),
            $f->f->gradingScales(),
            $f->service,
            new AcademicInputService(
                new SqlAcademicInputRepository($s->sql),
                $s->academicCredits(),
                $s->transferCredits(),
                $s->enrollmentLedger(),
                $students,
                $f->service,
                $s->transactions,
            ),
            new DegreeProgressEvaluator(
                new AcademicPolicyEvaluator(),
                new RetainedAttemptReader(new RetainedSectionReader($f->f->history)),
                new DegreeMinimumEvaluator(),
                new GroupAllocationEvaluator(
                    new CourseMatchEvaluator(),
                    new AcademicPolicyEvaluator(),
                    new CourseAllocationPlanner(),
                    new AllocationGapAnalyzer(),
                ),
                new DegreeWorkReview(),
            ),
            $students,
            $s->access,
            $s->transactions,
        );
    }

    private function digest(StudentRequirementsFixture $f): string
    {
        $rows = [];
        foreach ([
            'sis_history_events', 'sis_history_streams', 'sis_history_operations',
            'sis_degree_audit_templates', 'sis_course_groups', 'sis_student_requirements', 'sis_course_rules',
            'sis_academic_credits', 'sis_student_programs', 'sis_audit',
        ] as $table) {
            $rows[$table] = $f->f->sis->sql->rows($table);
        }
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /** A new, never-published degree/group using actual retained course publications. */
    private function draft(StudentRequirementsFixture $f): array
    {
        $s = $f->f->sis;
        $year = new EntityId(new AcademicCalendarFixture($s)->year(2028)['id']);
        $degree = new EntityId($f->f->degrees->open($s->program, $year)['id']);
        $id = EntityId::generate();
        $d = CourseGroupDefinitionV2::blank('Fictional trial group')->definition;
        $d['min_credits_milli'] = 3000;
        $d['courses'] = [$f->f->groupDefinition['courses'][0]];
        $d['rules'] = [[
            'id' => EntityId::generate()->value,
            'mode' => 'all',
            'minimum_courses' => 1,
            'course_ids' => [$d['courses'][0]['course_id']],
        ]];
        $f->f->groups->create($id, new CourseGroupDefinitionV2($d));
        $definition = $f->f->definition;
        $definition['min_credits_milli'] = 3000;
        $definition['min_resident_milli'] = 0;
        $definition['groups'] = [['group_id' => $id->value, 'version_id' => null]];
        return [$degree, new AuditRequirementsV3($definition), $id];
    }

    private function work(StudentRequirementsFixture $f): void
    {
        $s = $f->f->sis;
        $s->gradeScale([[
            'code' => 'A', 'min_score_milli' => 0, 'max_score_milli' => 100000,
            'points_milli' => 4000, 'passes' => true, 'counts_gpa' => true,
        ]]);
        $c = $f->f->groupDefinition['courses'][0];
        $section = new Section(
            EntityId::generate(), new EntityId($c['course_id']), $s->term, $s->access->actor,
            'FICTIONAL.TRIAL', 20, true, true, new Money(0, 'USD'),
        );
        $s->registration->addSection($section);
        $bindings = $f->f->catalog->bindings();
        $review = $bindings->review($section->id, new EntityId($c['version_id']), 'Fictional offering');
        $bindings->commit($section->id, $review['review_token']);
        $enrollment = $s->service->register($f->student, $section->id)->id;
        $grade = $s->academicCredits()->choices($f->student, $section->id, $enrollment)['items'][0]['id'];
        $review = $s->academicCredits()->review($f->student, $section->id, $enrollment, new EntityId($grade), 0);
        $s->academicCredits()->commit($f->student, $section->id, $review['review_token']);
    }

    public function testNeverPublishedDegreeAndGroupUseUnsavedSettingsWithoutWritingAnything(): void
    {
        $f = new StudentRequirementsFixture();
        $this->work($f);
        [$template, $definition, $group] = $this->draft($f);
        $before = $this->digest($f);
        $result = $this->service($f)->test($template, $f->student, $f->assignment, $definition);
        self::assertSame('review', $result['status']);
        self::assertSame('runtime_test', $result['mode']);
        self::assertNull($result['context']['requirements_version_id']);
        self::assertFalse($result['exceptions_applied']);
        self::assertSame('draft', $result['sources'][0]['source']);
        self::assertSame('feasible', $result['allocation']['outcome']);
        self::assertSame(3000, $result['minimums'][0]['required_milli']);
        self::assertSame($before, $this->digest($f));
        $d = $definition->definition;
        $d['min_credits_milli'] = 6000;
        $again = $this->service($f)->test($template, $f->student, $f->assignment, new AuditRequirementsV3($d));
        self::assertNotSame($result['definition_hash'], $again['definition_hash']);
        self::assertSame(6000, $again['minimums'][0]['required_milli']);
        self::assertSame('below_minimum', $again['minimums'][0]['status']);
        self::assertSame($before, $this->digest($f));
        self::assertNull($f->service->view($f->student, $f->assignment)['selection']);
    }

    public function testPinnedPublicationAndExplicitWorkingDraftChoiceRemainDifferent(): void
    {
        $f = new StudentRequirementsFixture();
        $d = $f->f->groupDefinition;
        $d['name'] = 'Changed working group';
        $f->f->groups->save($f->f->group, 0, new \Domain\DegreeAudit\ValueObject\CourseGroupDefinition($d));
        $definition = new AuditRequirementsV3($f->f->definition);
        $before = $this->digest($f);
        $service = $this->service($f);
        $pinned = $service->test($f->f->degree, $f->student, $f->assignment, $definition);
        $draft = $service->test($f->f->degree, $f->student, $f->assignment, $definition, true);
        self::assertSame('publication', $pinned['sources'][0]['source']);
        self::assertSame('General education', $pinned['sources'][0]['name']);
        self::assertSame('draft', $draft['sources'][0]['source']);
        self::assertSame('Changed working group', $draft['sources'][0]['name']);
        self::assertNotSame($pinned['definition_hash'], $draft['definition_hash']);
        self::assertSame($before, $this->digest($f));
    }

    public function testBothOfficePermissionsAndStudentPursuitScopeAreRequired(): void
    {
        $f = new StudentRequirementsFixture();
        $service = $this->service($f);
        $f->f->sis->access->staff = false;
        foreach ([[], ['sis:catalog:manage'], ['sis:student:read']] as $grants) {
            $f->f->sis->access->grants = $grants;
            try {
                $service->test($f->f->degree, $f->student, $f->assignment, new AuditRequirementsV3($f->f->definition));
                self::fail('A trial read student data without both office permissions.');
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
        $f->f->sis->access->staff = true;
        $this->expectException(RecordNotFoundException::class);
        $service->test($f->f->degree, EntityId::generate(), $f->assignment, new AuditRequirementsV3($f->f->definition));
    }

    public function testPublishedAuditKeepsItsOriginalGroupsGradingAndMinimumsAfterDraftAndScaleEdits(): void
    {
        $f = new StudentRequirementsFixture();
        $this->work($f);
        $row = $f->f->degrees->view($f->f->degree);
        $d = $f->f->definition;
        $d['min_credits_milli'] = 3000;
        $d['min_resident_milli'] = 0;
        $f->f->degrees->save($f->f->degree, $row['revision'], new AuditRequirementsV3($d));
        $version = new EntityId($f->f->publishDegree()['operation_id']);
        $service = $this->service($f);
        $before = $this->digest($f);
        $first = $service->testPublication($f->f->degree, $version, $f->student, $f->assignment);
        self::assertSame($before, $this->digest($f));
        self::assertSame('review', $first['status']);
        self::assertSame('publication', $first['context']['source']);
        self::assertSame($version->value, $first['context']['requirements_version_id']);
        self::assertSame(4000, $first['gpa']['overall']['milli']);
        $f->f->sis->gradeScale([[
            'code' => 'A', 'min_score_milli' => 0, 'max_score_milli' => 100000,
            'points_milli' => 2000, 'passes' => true, 'counts_gpa' => true,
        ]]);
        $g = $f->f->groupDefinition;
        $g['name'] = 'Changed group draft';
        $f->f->groups->save($f->f->group, 0, new \Domain\DegreeAudit\ValueObject\CourseGroupDefinition($g));
        $row = $f->f->degrees->view($f->f->degree);
        $d['name'] = 'Changed degree draft';
        $d['min_credits_milli'] = 6000;
        $f->f->degrees->save($f->f->degree, $row['revision'], new AuditRequirementsV3($d));
        $before = $this->digest($f);
        $again = $service->testPublication($f->f->degree, $version, $f->student, $f->assignment);
        self::assertSame($first, $again);
        self::assertSame('General education', $again['sources'][0]['name']);
        self::assertSame(3000, $again['minimums'][0]['required_milli']);
        self::assertSame($before, $this->digest($f));
        self::assertNull($f->service->view($f->student, $f->assignment)['selection']);
    }

    public function testDraftAndPublishedAuditsFromAnotherProgramRemainWhatIfComparisons(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        $other = EntityId::generate();
        $s->sql->insert('sis_programs', ['id' => $other->value, 'code' => 'BA.ENG', 'title' => 'Fictional English']);
        $row = $f->f->degrees->open($other, $f->f->year);
        $template = new EntityId($row['id']);
        $d = new AuditRequirementsV3($f->f->definition);
        $f->f->degrees->save($template, $row['revision'], $d);
        $review = $f->f->degrees->review($template, 'Fictional what-if plan');
        $version = new EntityId($f->f->degrees->publish($template, $review['review_token'])['operation_id']);
        $before = $this->digest($f);
        $service = $this->service($f);
        self::assertSame('review', $service->test($template, $f->student, $f->assignment, $d)['status']);
        self::assertSame('review', $service->testPublication($template, $version, $f->student, $f->assignment)['status']);
        self::assertSame($before, $this->digest($f));
        self::assertSame($s->program->value, (new SqlStudentRecordRepository($s->sql))->assignment($f->assignment)->programId->value);
    }

    public function testPublishedVersionMustBelongToTheSelectedDegreeAndShareTheReplayBudget(): void
    {
        $f = new StudentRequirementsFixture();
        [$other] = $this->draft($f);
        $before = $this->digest($f);
        $service = $this->service($f);
        self::assertSame(['status' => 'inconsistent', 'mode' => 'runtime_test'], $service->testPublication(
            $other, $f->version, $f->student, $f->assignment,
        ));
        self::assertSame(['status' => 'limited', 'mode' => 'runtime_test'], $service->testPublication(
            $f->f->degree, $f->version, $f->student, $f->assignment,
            new HistoryReadBudget(maximumPages: 1, maximumEvents: 1),
        ));
        self::assertSame($before, $this->digest($f));
    }

    public function testAnUnverifiableSelectedCourseReturnsNoPartialTrial(): void
    {
        $f = new StudentRequirementsFixture();
        [$degree, $definition, $group] = $this->draft($f);
        $repository = new SqlCourseGroupRepository($f->f->sis->sql);
        $row = $repository->get($group);
        $d = $row['definition'];
        $d['courses'][0]['version_id'] = $f->f->groupDefinition['courses'][1]['version_id'];
        $repository->save($group, $row['revision'], $d);
        $before = $this->digest($f);
        $result = $this->service($f)->test($degree, $f->student, $f->assignment, $definition);
        self::assertSame(['status' => 'inconsistent', 'mode' => 'runtime_test'], $result);
        self::assertSame($before, $this->digest($f));
    }

    public function testSharedReplayExhaustionReturnsNoPartialRecordsOrPlacements(): void
    {
        $f = new StudentRequirementsFixture();
        [$degree, $definition] = $this->draft($f);
        $before = $this->digest($f);
        $budget = new HistoryReadBudget(maximumPages: 1, maximumEvents: 1);
        $result = $this->service($f)->test($degree, $f->student, $f->assignment, $definition, budget: $budget);
        self::assertSame(['status' => 'limited', 'mode' => 'runtime_test'], $result);
        self::assertSame($before, $this->digest($f));
    }
}
