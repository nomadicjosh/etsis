<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/StudentRequirementsFixture.php';
require_once dirname(__DIR__) . '/Support/DegreeProgressFixture.php';

use Domain\Catalog\Service\PublishedCourseReader;
use Domain\DegreeAudit\Service\AcademicInputService;
use Domain\DegreeAudit\Service\AcademicPolicyEvaluator;
use Domain\DegreeAudit\Service\AllocationGapAnalyzer;
use Domain\DegreeAudit\Service\CourseAllocationPlanner;
use Domain\DegreeAudit\Service\CourseMatchEvaluator;
use Domain\DegreeAudit\Service\DegreeAuditTrialService;
use Domain\DegreeAudit\Service\DegreeMinimumEvaluator;
use Domain\DegreeAudit\Service\DegreeProgressEvaluator;
use Domain\DegreeAudit\Service\DegreeWorkReview;
use Domain\DegreeAudit\Service\GroupAllocationEvaluator;
use Domain\DegreeAudit\Service\PublishedRequirementsReader;
use Domain\DegreeAudit\Service\RetainedAttemptReader;
use Domain\DegreeAudit\Service\RuleAllocationCompiler;
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
    private function service(StudentRequirementsFixture $f, ?\Domain\DegreeAudit\Repository\AcademicInputRepository $repository = null): DegreeAuditTrialService
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
                new \Domain\DegreeAudit\Service\AcademicInputVerifier(
                    $repository ?? new SqlAcademicInputRepository($s->sql),
                    $s->academicCredits(),
                    $s->transferCredits(),
                    $s->enrollmentLedger(),
                ),
                $students,
                $f->service,
                $s->transactions,
                $s->workPursuits(),
            ),
            $evaluator = new DegreeProgressEvaluator(
                new AcademicPolicyEvaluator(),
                new RetainedAttemptReader(new RetainedSectionReader($f->f->history)),
                new DegreeMinimumEvaluator(),
                new GroupAllocationEvaluator(
                    new CourseMatchEvaluator(),
                    new AcademicPolicyEvaluator(),
                    new CourseAllocationPlanner(),
                    new AllocationGapAnalyzer(),
                    new RuleAllocationCompiler(new CourseMatchEvaluator()),
                    new \Domain\DegreeAudit\Service\AllocationCoverageReview(),
                ),
                new DegreeWorkReview(),
                new \Domain\DegreeAudit\Service\SavedCourseRulePreview(new \Domain\Catalog\Service\PublishedCourseReader($f->f->history)),
                new \Domain\DegreeAudit\Service\GradingRepublicationVerifier(
                    new \Domain\Gradebook\Service\RetainedGradingReader($f->f->history),
                ),
            ),
            $students,
            $s->access,
            $s->transactions,
            new \Domain\DegreeAudit\Service\CombinedAllocationEvaluator(
                $evaluator, new CourseAllocationPlanner(), new \Domain\DegreeAudit\Service\AllocationCoverageReview(),
                new AllocationGapAnalyzer(),
            ),
            DegreeProgressFixture::service($s, $f->f->history, $f->service, $f->f->catalog->fixture->cipher, $repository),
            $s->clock,
        );
    }

    private function digest(StudentRequirementsFixture $f): string
    {
        $rows = [];
        foreach ([
            'sis_history_events', 'sis_history_streams', 'sis_history_operations',
            'sis_degree_audit_templates', 'sis_specialization_audit_templates', 'sis_course_groups', 'sis_student_requirements', 'sis_course_rules',
            'sis_academic_credits', 'sis_student_programs', 'sis_audit', 'sis_requirement_adjustments',
            'sis_student_specializations', 'sis_student_specialization_requirements',
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
        $named = $result['allocation']['groups'][0]['course_review'][0];
        self::assertTrue($named['required']);
        self::assertSame('proposed', $named['placement']);
        self::assertSame('candidate', $named['attempts'][0]['status']);
        self::assertTrue($named['attempts'][0]['selected']);
        self::assertSame(3000, $result['minimums'][0]['required_milli']);
        self::assertSame($before, $this->digest($f));
        $d = $definition->definition;
        $d['min_credits_milli'] = 6000;
        $again = $this->service($f)->test($template, $f->student, $f->assignment, new AuditRequirementsV3($d));
        self::assertNotSame($result['definition_hash'], $again['definition_hash']);
        self::assertSame(6000, $again['minimums'][0]['required_milli']);
        self::assertSame('below_minimum', $again['minimums'][0]['status']);
        self::assertSame($named, $again['allocation']['groups'][0]['course_review'][0]);
        self::assertSame($before, $this->digest($f));
        self::assertNull($f->service->view($f->student, $f->assignment)['selection']);
    }

    public function testApprovedExceptionsRequireAnExplicitExactAssignedPublicationAndRemainRuntimeOnly(): void
    {
        $f = new StudentRequirementsFixture();
        $f->assign();
        $s = $f->f->sis;
        $adjustments = DegreeProgressFixture::adjustments($s, $f->f->history, $f->service, $f->f->catalog->fixture->cipher);
        $review = $adjustments->reviewGrant($f->student, $f->assignment,
            ['kind' => 'minimum', 'group_id' => null, 'minimum' => 'min_credits_milli', 'required_milli' => 90000],
            'Fictional protected minimum reduction.');
        $adjustments->commit($f->student, $f->assignment, $review['review_token']);
        $before = $this->digest($f);
        $trials = $this->service($f);
        $baseline = $trials->testPublication($f->f->degree, $f->version, $f->student, $f->assignment);
        $adjusted = $trials->testPublication($f->f->degree, $f->version, $f->student, $f->assignment,
            includeExceptions: true);
        self::assertFalse($baseline['exceptions_applied']);
        self::assertSame(120000, $baseline['minimums'][0]['required_milli']);
        self::assertTrue($adjusted['exceptions_applied']);
        self::assertSame('assigned_publications', $adjusted['exception_mode']);
        self::assertSame('runtime_test', $adjusted['mode']);
        self::assertSame(90000, $adjusted['minimums'][0]['required_milli']);
        self::assertTrue($adjusted['minimums'][0]['adjusted']);
        self::assertSame($f->version->value, $adjusted['context']['requirements_version_id']);
        self::assertSame('publication', $adjusted['sources'][0]['source']);
        self::assertStringNotContainsString('Fictional protected minimum', json_encode($adjusted, JSON_THROW_ON_ERROR));
        self::assertSame($before, $this->digest($f));
    }

    public function testAssignedExceptionTrialsRejectAnotherPublicationAndSharingOverrides(): void
    {
        $f = new StudentRequirementsFixture();
        $f->assign();
        $version = new EntityId($f->f->publishDegree()['operation_id']);
        $trials = $this->service($f);
        $before = $this->digest($f);
        foreach ([
            fn () => $trials->testPublication($f->f->degree, $version, $f->student, $f->assignment, includeExceptions: true),
            fn () => $trials->testPublication($f->f->degree, $f->version, $f->student, $f->assignment,
                sharing: \Domain\Catalog\ValueObject\ComponentCourseSharing::MultipleComponents, includeExceptions: true),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('An exception test silently changed its assigned context.');
            } catch (\Domain\Shared\Exception\ValidationException $e) {
                self::assertSame('include_exceptions', $e->errors[0]['field']);
                self::assertSame($before, $this->digest($f));
            }
        }
        self::assertSame('review', $trials->testPublication($f->f->degree, $version, $f->student, $f->assignment)['status']);
        $limited = $trials->testPublication($f->f->degree, $f->version, $f->student, $f->assignment,
            new HistoryReadBudget(1, 1), includeExceptions: true);
        self::assertSame(['status' => 'limited', 'mode' => 'runtime_test', 'exceptions_applied' => false,
            'exception_mode' => 'assigned_publications'], $limited);
        self::assertSame($before, $this->digest($f));
    }

    public function testCombinedAssignedTrialsReuseOneInventoryAndThePublishedSharingPolicy(): void
    {
        require_once dirname(__DIR__) . '/Support/StudentSpecializationRequirementsFixture.php';
        $f = new StudentRequirementsFixture();
        $specializations = new StudentSpecializationRequirementsFixture($f->f, 'multiple_components');
        $f->student = $specializations->student;
        $f->assignment = $specializations->assignment;
        $f->version = new EntityId($f->f->publishDegree()['operation_id']);
        $f->assign();
        $specializations->assign();
        $s = $f->f->sis;
        $adjustments = DegreeProgressFixture::adjustments($s, $f->f->history, $f->service,
            $f->f->catalog->fixture->cipher, $specializations->service);
        $review = $adjustments->reviewGrant($f->student, $f->assignment,
            ['kind' => 'minimum', 'group_id' => null, 'minimum' => 'min_credits_milli', 'required_milli' => 90000],
            'Fictional specialization minimum.', new EntityId($specializations->choices['DATA']['id']));
        $adjustments->commit($f->student, $f->assignment, $review['review_token'],
            new EntityId($specializations->choices['DATA']['id']));
        $this->work($f);
        $course = $f->f->groupDefinition['courses'][0];
        $route = $adjustments->reviewGrant($f->student, $f->assignment,
            ['kind' => 'component_route', 'course_id' => $course['course_id'], 'course_version_id' => $course['version_id'],
                'target_version_id' => $specializations->versions['DATA']], 'Fictional routing to the specialization.');
        $adjustments->commit($f->student, $f->assignment, $route['review_token']);
        $inventory = new class ($s) implements \Domain\DegreeAudit\Repository\AcademicInputRepository {
            public int $reads = 0;
            public function __construct(private SisFixture $sis) {}
            public function inventory(EntityId $student): \Domain\DegreeAudit\ValueObject\AcademicInputInventory
            {
                $this->reads++;
                return new SqlAcademicInputRepository($this->sis->sql)->inventory($student);
            }
        };
        $companions = [['template_id' => $specializations->plans['DATA']['id'], 'source' => 'publication',
            'version_id' => $specializations->versions['DATA']]];
        $before = $this->digest($f);
        $result = $this->service($f, $inventory)->testPublication($f->f->degree, $f->version, $f->student, $f->assignment,
            companions: $companions, includeExceptions: true);
        self::assertSame(1, $inventory->reads);
        self::assertSame('runtime_combined_test', $result['mode']);
        self::assertSame('published_program', $result['sharing_source']);
        self::assertSame('multiple_components', $result['sharing']);
        self::assertTrue($result['exceptions_applied']);
        self::assertCount(2, $result['components']);
        self::assertSame(120000, $result['components'][0]['progress']['minimums'][0]['required_milli']);
        self::assertSame(90000, $result['components'][1]['progress']['minimums'][0]['required_milli']);
        self::assertContains('component_excluded', $result['components'][0]['progress']['allocation']['records'][0]['statuses']);
        self::assertNotContains('component_excluded', $result['components'][1]['progress']['allocation']['records'][0]['statuses']);
        foreach ($result['components'] as $component) {
            self::assertTrue($component['progress']['context']['exceptions_applied']);
            self::assertSame('publication', $component['progress']['sources'][0]['source']);
        }
        self::assertSame($before, $this->digest($f));
        foreach ([
            [['template_id' => $specializations->plans['DATA']['id'], 'source' => 'draft']],
            [...$companions, ['template_id' => $specializations->plans['ART']['id'], 'source' => 'publication',
                'version_id' => $specializations->versions['ART']]],
        ] as $invalid) {
            try {
                $this->service($f)->testPublication($f->f->degree, $f->version, $f->student, $f->assignment,
                    companions: $invalid, includeExceptions: true);
                self::fail('A combined approved-exception trial accepted unassigned or draft components.');
            } catch (\Domain\Shared\Exception\ValidationException) {
                self::assertSame($before, $this->digest($f));
            }
        }
        $single = $this->service($f)->testPublication(new EntityId($specializations->plans['DATA']['id']),
            new EntityId($specializations->versions['DATA']), $f->student, $f->assignment, includeExceptions: true);
        self::assertSame('runtime_test', $single['mode']);
        self::assertTrue($single['exceptions_applied']);
        self::assertSame(90000, $single['minimums'][0]['required_milli']);
        self::assertSame($before, $this->digest($f));
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

    private function savedCourseRule(StudentRequirementsFixture $f, array $tree, string $code = 'DA.FICTIONAL'): array
    {
        $s = $f->f->sis;
        $service = new \Domain\Rules\Service\CourseRuleService(
            new \Infrastructure\Persistence\Repository\SqlCourseRuleRepository($s->sql),
            new PublishedCourseReader($f->f->history),
            $s->access, $s->transactions, $s->audit, $s->clock,
        );
        return $service->save(null, null, $code, 'Fictional course eligibility', $tree);
    }

    private function ruleGroup(StudentRequirementsFixture $f, array $saved): array
    {
        $id = EntityId::generate();
        $d = CourseGroupDefinitionV2::blank('Fictional rule-based group')->definition;
        $d['min_credits_milli'] = 3000;
        $d['rules'] = [[
            'id' => EntityId::generate()->value, 'mode' => 'credits', 'minimum_courses' => 0,
            'minimum_credits_milli' => 3000, 'course_ids' => [], 'eligibility' => $saved,
        ]];
        $f->f->groups->create($id, new CourseGroupDefinitionV2($d));
        return [$id, $d];
    }

    public function testSavedRuleBindingIsRetainedThroughPublicationAndRuntimeEligibilityReads(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        $subject = EntityId::generate();
        $other = EntityId::generate();
        $subjects = new \Infrastructure\Persistence\Repository\SqlSubjectRepository($s->sql);
        $subjects->save(new \Domain\Catalog\ValueObject\SubjectSnapshot($subject, 'MATH', 'Mathematics'), true, true);
        $subjects->save(new \Domain\Catalog\ValueObject\SubjectSnapshot($other, 'OTHER', 'Other'), true, true);
        $course = new EntityId($f->f->groupDefinition['courses'][0]['course_id']);
        $subjects->assign($course, $subject);
        $publications = $f->f->catalog->publications();
        $review = $publications->review($course, 'Fictional subject publication');
        $f->f->groupDefinition['courses'][0]['version_id'] = $publications->publish($course, $review['review_token'])['operation_id'];
        $this->work($f);
        $saved = $this->savedCourseRule($f, ['condition'=>'AND','rules'=>[['id'=>'subject_id','operator'=>'equal','value'=>$subject->value]]]);
        [$group, $definition] = $this->ruleGroup($f, $saved);
        $degree = $f->f->definition;
        $degree['min_credits_milli'] = 3000;
        $degree['min_resident_milli'] = 0;
        $degree['groups'] = [['group_id'=>$group->value,'version_id'=>null]];
        $subjects->assign($course, $other);
        $before = $this->digest($f);
        $trial = $this->service($f)->test($f->f->degree, $f->student, $f->assignment, new AuditRequirementsV3($degree));
        self::assertSame('matches', $trial['rule_eligibility'][0]['outcome']);
        self::assertSame(1, $trial['rule_eligibility'][0]['rule_revision']);
        self::assertSame('feasible', $trial['allocation']['outcome']);
        self::assertSame([], $trial['allocation']['issues']);
        self::assertTrue($trial['work_summary']['placement_established']);
        self::assertSame($before, $this->digest($f));
        $review = $f->f->groups->review($group, 'Fictional rule-backed group');
        $groupVersion = $f->f->groups->publish($group, $review['review_token'])['operation_id'];
        $degree['groups'][0]['version_id'] = $groupVersion;
        $row = $f->f->degrees->view($f->f->degree);
        $f->f->degrees->save($f->f->degree, $row['revision'], new AuditRequirementsV3($degree));
        $version = new EntityId($f->f->publishDegree()['operation_id']);
        $s->sql->update('sis_course_rules', ['definition_json'=>'damaged authoring reference'], ['id'=>$saved['id']]);
        $s->access->staff = false;
        $s->access->grants = ['sis:catalog:manage','sis:student:read'];
        $before = $this->digest($f);
        $published = $this->service($f)->testPublication($f->f->degree, $version, $f->student, $f->assignment);
        self::assertSame('matches', $published['rule_eligibility'][0]['outcome']);
        self::assertSame('feasible', $published['allocation']['outcome']);
        self::assertSame(3000, $published['allocation']['groups'][0]['placements'][0]['credits_milli']);
        self::assertSame(1, $published['rule_eligibility'][0]['rule_revision']);
        self::assertSame($before, $this->digest($f));
        $private = new \Domain\Portal\ValueObject\StudentDegreeProgress($published)->describe();
        self::assertArrayNotHasKey('rule_eligibility', $private);
        self::assertStringNotContainsString('"eligibility":', json_encode($private));
    }

    public function testRepeatedAcceptedPublicationsKeepTheirOwnRuleCreditsInDraftAndPublishedTests(): void
    {
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        $subjects = new \Infrastructure\Persistence\Repository\SqlSubjectRepository($s->sql);
        $course = new EntityId($f->f->groupDefinition["courses"][0]["course_id"]);
        $cards = [];
        foreach (["ENG", "HUM"] as $index => $code) {
            $subject = EntityId::generate();
            $subjects->save(
                new \Domain\Catalog\ValueObject\SubjectSnapshot($subject, $code, "Fictional " . $code),
                true,
                true,
            );
            $subjects->assign($course, $subject);
            $publications = $f->f->catalog->publications();
            $review = $publications->review($course, "Fictional subject change");
            $f->f->groupDefinition["courses"][0]["version_id"] = $publications->publish(
                $course,
                $review["review_token"],
            )["operation_id"];
            if ($index === 1) {
                $s->term = EntityId::generate();
                $s->sql->insert("sis_terms", [
                    "id" => $s->term->value,
                    "code" => "FICTIONAL.REPEAT",
                    "starts_on" => "2026-08-01",
                    "ends_on" => "2026-12-20",
                    "add_drop_ends_on" => "2026-08-15",
                ]);
                $s->transactions->run(
                    fn() => $s->registration->configureWindow(
                        $s->term,
                        new \Domain\Registration\RegistrationWindow(
                            new \Domain\Shared\ValueObject\DateRange(
                                new DateTimeImmutable("2026-08-01"),
                                new DateTimeImmutable("2026-08-31"),
                            ),
                            5,
                        ),
                    ),
                );
            }
            $this->work($f);
            $saved = $this->savedCourseRule(
                $f,
                [
                    "condition" => "AND",
                    "rules" => [["id" => "subject_id", "operator" => "equal", "value" => $subject->value]],
                ],
                "DA." . $code,
            );
            $cards[] = [
                "id" => EntityId::generate()->value,
                "mode" => "credits",
                "minimum_courses" => 0,
                "minimum_credits_milli" => 3000,
                "course_ids" => [],
                "eligibility" => $saved,
            ];
        }
        [$group, $groupDefinition] = $this->ruleGroup($f, $cards[0]["eligibility"]);
        $groupDefinition["min_credits_milli"] = 6000;
        $groupDefinition["rules"] = $cards;
        $f->f->groups->save($group, 0, new CourseGroupDefinitionV2($groupDefinition));
        $degree = $f->f->definition;
        $degree["min_credits_milli"] = 6000;
        $degree["min_resident_milli"] = 0;
        $degree["groups"] = [["group_id" => $group->value, "version_id" => null]];
        $degree["policies"]["repeat_credit"] = "all_eligible";
        $service = $this->service($f);
        $before = $this->digest($f);
        $draft = $service->test($f->f->degree, $f->student, $f->assignment, new AuditRequirementsV3($degree));
        self::assertSame("feasible", $draft["allocation"]["outcome"]);
        $p = $draft["allocation"]["groups"][0]["placements"][0];
        self::assertSame(6000, $p["credits_milli"]);
        self::assertCount(2, $p["work_options"][0]);
        self::assertSame([3000, 3000], array_values($p["rule_credits_milli"]));
        self::assertSame($before, $this->digest($f));
        $degree["policies"]["repeat_credit"] = "highest_earned";
        $before = $this->digest($f);
        $highest = $service->test(
            $f->f->degree,
            $f->student,
            $f->assignment,
            new AuditRequirementsV3($degree),
        );
        self::assertSame("infeasible", $highest["allocation"]["outcome"]);
        self::assertSame([], $highest["allocation"]["groups"][0]["placements"]);
        self::assertSame($before, $this->digest($f));
        $degree["policies"]["repeat_credit"] = "all_eligible";
        $review = $f->f->groups->review($group, "Fictional mixed rule group");
        $degree["groups"][0]["version_id"] = $f->f->groups->publish($group, $review["review_token"])[
        "operation_id"
        ];
        $row = $f->f->degrees->view($f->f->degree);
        $f->f->degrees->save($f->f->degree, $row["revision"], new AuditRequirementsV3($degree));
        $version = new EntityId($f->f->publishDegree()["operation_id"]);
        $before = $this->digest($f);
        $published = $service->testPublication($f->f->degree, $version, $f->student, $f->assignment);
        self::assertSame(
            $draft["allocation"]["groups"][0]["placements"],
            $published["allocation"]["groups"][0]["placements"],
        );
        self::assertSame($before, $this->digest($f));
        $private = new \Domain\Portal\ValueObject\StudentDegreeProgress($published)->describe();
        self::assertStringNotContainsString("rule_credits_milli", json_encode($private, JSON_THROW_ON_ERROR));
        foreach ($cards as $card) {
            self::assertStringNotContainsString($card["id"], json_encode($private, JSON_THROW_ON_ERROR));
        }
    }

    public function testExplicitCurriculumPublicationsWorkInDraftAndPublishedTestsWithoutRewritingWork(): void
    {
        $f = new StudentRequirementsFixture();
        $this->work($f);
        $s = $f->f->sis;
        $original = $f->f->groupDefinition["courses"][0];
        $course = new EntityId($original["course_id"]);
        $s->sql->update(
            "sis_courses",
            ["title" => "Fictional revised curriculum title", "credits_milli" => 4000],
            ["id" => $course->value],
        );
        $pub = $f->f->catalog->publications();
        $r = $pub->review($course, "Fictional new publication");
        $current = $pub->publish($course, $r["review_token"])["operation_id"];
        [$template, $degree, $group] = $this->draft($f);
        $row = $f->f->groups->view($group);
        $d = $row["definition"];
        $d["courses"][0]["version_id"] = $current;
        $f->f->groups->save($group, $row["revision"], new CourseGroupDefinitionV2($d));
        $service = $this->service($f);
        $before = $this->digest($f);
        self::assertSame(
            "needs_review",
            $service->test($template, $f->student, $f->assignment, $degree)["allocation"]["outcome"],
        );
        self::assertSame($before, $this->digest($f));
        $d["courses"][0]["accepted_version_ids"] = [$original["version_id"]];
        $row = $f->f->groups->view($group);
        $f->f->groups->save($group, $row["revision"], new CourseGroupDefinitionV2($d));
        $before = $this->digest($f);
        $draft = $service->test($template, $f->student, $f->assignment, $degree);
        self::assertSame("feasible", $draft["allocation"]["outcome"]);
        $p = $draft["allocation"]["groups"][0]["placements"][0];
        self::assertSame(3000, $p["credits_milli"]);
        self::assertSame("Fictional revised curriculum title", $p["target_title"]);
        self::assertNotSame($p["target_title"], $p["course_title"]);
        self::assertSame($before, $this->digest($f));
        $review = $f->f->groups->review($group, "Fictional accepted versions");
        $groupVersion = $f->f->groups->publish($group, $review["review_token"])["operation_id"];
        $dd = $degree->definition;
        $dd["groups"][0]["version_id"] = $groupVersion;
        $f->f->degrees->save($template, 0, new AuditRequirementsV3($dd));
        $review = $f->f->degrees->review($template, "Fictional curriculum acceptance");
        $version = new EntityId($f->f->degrees->publish($template, $review["review_token"])["operation_id"]);
        $d["courses"][0]["accepted_version_ids"] = [];
        $row = $f->f->groups->view($group);
        $f->f->groups->save($group, $row["revision"], new CourseGroupDefinitionV2($d));
        $before = $this->digest($f);
        $published = $service->testPublication($template, $version, $f->student, $f->assignment);
        self::assertSame("feasible", $published["allocation"]["outcome"]);
        self::assertSame($p, $published["allocation"]["groups"][0]["placements"][0]);
        self::assertSame($before, $this->digest($f));
        self::assertSame(
            [$original["version_id"]],
            array_column(
                $published["allocation"]["groups"][0]["courses"][$course->value]["accepted_versions"],
                "version_id",
            ),
        );
        $reader = new PublishedCourseReader($f->f->history);
        $both = $reader->selectedVersions(
            $course,
            [new EntityId($original["version_id"]), new EntityId($current)],
            new HistoryReadBudget(1, 20),
        );
        self::assertCount(2, $both);
    }

    public function testForeignAcceptedPublicationCannotBeSavedOrChangeHistory(): void
    {
        $f = new StudentRequirementsFixture();
        [$template, $degree, $group] = $this->draft($f);
        $row = $f->f->groups->view($group);
        $d = $row["definition"];
        $d["courses"][0]["accepted_version_ids"] = [$f->f->groupDefinition["courses"][1]["version_id"]];
        $before = $this->digest($f);
        try {
            $f->f->groups->save($group, $row["revision"], new CourseGroupDefinitionV2($d));
            self::fail("A foreign publication was accepted.");
        } catch (RecordNotFoundException) {
            self::assertSame($before, $this->digest($f));
        }
    }

    public function testMissingPublishedClassificationsRemainUnknownForSavedRulePreview(): void
    {
        $f = new StudentRequirementsFixture();
        $this->work($f);
        $saved = $this->savedCourseRule($f, ['condition'=>'AND','rules'=>[['id'=>'course_level_number','operator'=>'greater','value'=>200]]]);
        [$group] = $this->ruleGroup($f,$saved);
        $degree = $f->f->definition;
        $degree['groups'] = [['group_id'=>$group->value,'version_id'=>null]];
        $before = $this->digest($f);
        $result = $this->service($f)->test($f->f->degree,$f->student,$f->assignment,new AuditRequirementsV3($degree));
        self::assertSame('unknown',$result['rule_eligibility'][0]['outcome']);
        self::assertSame($before,$this->digest($f));
    }

    public function testForgedRuleConditionsAndLabelsCannotBeSavedAsCurriculumFacts(): void
    {
        $f = new StudentRequirementsFixture();
        $saved = $this->savedCourseRule($f,['condition'=>'AND','rules'=>[['id'=>'course_level_number','operator'=>'greater','value'=>200]]]);
        foreach (['title','definition'] as $field) {
            $forged = $saved;
            if ($field === 'title') $forged['title'] = 'Forged label';
            else {
                $forged['definition']['tree']['rules'][0]['value'] = 100;
                $forged['definition_hash'] = hash('sha256',json_encode($forged['definition'],JSON_THROW_ON_ERROR));
            }
            $before = $this->digest($f);
            try {
                $this->ruleGroup($f,$forged);
                self::fail('A modified saved-rule snapshot was accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame($before,$this->digest($f));
            }
        }
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
    public function testSpecializationDraftAndPublicationUseTheSameRuntimeEngineWithoutChangingStudentSelections(): void
    {
        require_once dirname(__DIR__) . '/Support/ProgramPublicationFixture.php';
        $f = new StudentRequirementsFixture();
        $s = $f->f->sis;
        $this->work($f);
        [, $definition] = $this->draft($f);
        $choice = new \Domain\Catalog\Service\ProgramSpecializationService(
            new \Infrastructure\Persistence\Repository\SqlProgramSpecializationRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
            $s->access, $s->transactions, $s->audit,
            new \Infrastructure\Persistence\Repository\SqlClassificationRepository($s->sql),
        )->save($s->program, null, null, 'DATA', 'Fictional data path', null, true);
        $scope = $f->f->degrees->open($s->program, $f->f->year, new EntityId($choice['id']));
        $id = new EntityId($scope['id']);
        $f->f->degrees->save($id, 0, $definition);
        $before = $this->digest($f);
        $service = $this->service($f);
        $result = $service->test($id, $f->student, $f->assignment, $definition);
        self::assertSame('review', $result['status']);
        self::assertSame($choice['id'], $result['context']['specialization']['id']);
        self::assertSame('draft', $result['sources'][0]['source']);
        self::assertSame($before, $this->digest($f));
        $group = new EntityId($definition->definition['groups'][0]['group_id']);
        $groupReview = $f->f->groups->review($group, 'Fictional specialization group approval.');
        $groupVersion = $f->f->groups->publish($group, $groupReview['review_token']);
        $publishedDefinition = $definition->definition;
        $publishedDefinition['groups'][0]['version_id'] = $groupVersion['operation_id'];
        $f->f->degrees->save($id, 1, new AuditRequirementsV3($publishedDefinition));
        $program = new ProgramPublicationFixture($s);
        $programReview = $program->service->review($s->program, $f->f->year, 'Fictional specialization program.');
        $program->service->publish($s->program, $programReview['review_token']);
        $review = $f->f->degrees->review($id, 'Fictional specialization requirements.');
        $version = new EntityId($f->f->degrees->publish($id, $review['review_token'])['operation_id']);
        $before = $this->digest($f);
        $published = $service->testPublication($id, $version, $f->student, $f->assignment);
        self::assertSame('review', $published['status']);
        self::assertSame($choice['id'], $published['context']['specialization']['id']);
        self::assertSame('publication', $published['sources'][0]['source']);
        self::assertSame($version->value, $published['context']['requirements_version_id']);
        self::assertSame($before, $this->digest($f));
    }

    private function combinedFixture(): array
    {
        $f = new StudentRequirementsFixture();
        $this->work($f);
        [$degree, $definition, $group] = $this->draft($f);
        $s = $f->f->sis;
        $choice = new \Domain\Catalog\Service\ProgramSpecializationService(
            new \Infrastructure\Persistence\Repository\SqlProgramSpecializationRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            new \Infrastructure\Persistence\Repository\SqlClassificationRepository($s->sql),
        )->save($s->program, null, null, "DATA", "Fictional data path", null, true);
        $year = new EntityId(new SqlRequirementsRepository($s->sql)->get($degree)["academic_year_id"]);
        $row = $f->f->degrees->open($s->program, $year, new EntityId($choice["id"]));
        $specialization = new EntityId($row["id"]);
        $f->f->degrees->save($specialization, 0, $definition);
        return [$f, $degree, $definition, $specialization, $group];
    }
    public function testCombinedDraftsUseOneInventoryAndOneSharingAwarePlanWithoutCreatingPublications(): void
    {
        [$f, $degree, $definition, $specialization] = $this->combinedFixture();
        $repository = new class ($f->f->sis) implements \Domain\DegreeAudit\Repository\AcademicInputRepository {
            public int $reads = 0;
            public function __construct(private \SisFixture $s) {}
            public function inventory(EntityId $student): \Domain\DegreeAudit\ValueObject\AcademicInputInventory
            {
                $this->reads++;
                return new SqlAcademicInputRepository($this->s->sql)->inventory($student);
            }
        };
        $service = $this->service($f, $repository);
        $before = $this->digest($f);
        $companions = [["template_id" => $specialization->value, "source" => "draft", "use_group_drafts" => true]];
        foreach (\Domain\Catalog\ValueObject\ComponentCourseSharing::cases() as $sharing) {
            $result = $service->test(
                $degree,
                $f->student,
                $f->assignment,
                $definition,
                companions: $companions,
                sharing: $sharing,
            );
            self::assertSame("runtime_combined_test", $result["mode"]);
            self::assertSame("explicit_hypothesis", $result["sharing_source"]);
            self::assertFalse($result["exceptions_applied"]);
            self::assertSame($sharing->value === "one_component" ? "infeasible" : "feasible", $result["outcome"]);
            self::assertCount(2, $result["components"]);
            foreach ($result["components"] as $component) {
                $progress = $component["progress"];
                self::assertNull($progress["context"]["requirements_version_id"]);
                self::assertSame("draft", $progress["context"]["test_source"]);
                self::assertSame("draft", $progress["sources"][0]["source"]);
                self::assertSame(3000, $progress["minimums"][0]["actual_minimum_milli"]);
                self::assertCount(
                    $sharing->value === "one_component" ? 0 : 1,
                    $progress["allocation"]["groups"][0]["placements"],
                );
            }
        }
        self::assertSame(2, $repository->reads);
        self::assertSame($before, $this->digest($f));
    }
    public function testCombinedSourcesRejectDuplicatesForeignYearsDegreesAndClientDefinitions(): void
    {
        [$f, $degree, $definition, $specialization] = $this->combinedFixture();
        $service = $this->service($f);
        $choice = ["template_id" => $specialization->value, "source" => "draft"];
        $invalid = [
            [$choice, $choice],
            [["template_id" => $degree->value, "source" => "draft"]],
            [["template_id" => $f->f->degree->value, "source" => "draft"]],
            [$choice + ["definition" => $definition->definition]],
            [$choice + ["use_group_drafts" => "yes"]],
            [$choice + ["version_id" => EntityId::generate()->value]],
        ];
        $before = $this->digest($f);
        foreach ($invalid as $companions) {
            try {
                $service->test(
                    $degree,
                    $f->student,
                    $f->assignment,
                    $definition,
                    companions: $companions,
                    sharing: \Domain\Catalog\ValueObject\ComponentCourseSharing::OneComponent,
                );
                self::fail("An invalid combined source was accepted.");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $service->test($degree, $f->student, $f->assignment, $definition, companions: [$choice]);
            self::fail("A combined trial silently chose a sharing policy.");
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        self::assertSame($before, $this->digest($f));
    }
    public function testCombinedPublicationUsesExactSourceWhileDraftAndGroupEditsRemainHypothetical(): void
    {
        [$f, $degree, $definition, $specialization, $group] = $this->combinedFixture();
        $groupReview = $f->f->groups->review($group, "Fictional test group publication.");
        $groupVersion = $f->f->groups->publish($group, $groupReview["review_token"])["operation_id"];
        $d = $definition->definition;
        $d["groups"][0]["version_id"] = $groupVersion;
        $f->f->degrees->save($specialization, 1, new AuditRequirementsV3($d));
        require_once dirname(__DIR__) . "/Support/ProgramPublicationFixture.php";
        $program = new ProgramPublicationFixture($f->f->sis);
        $year = new EntityId(new SqlRequirementsRepository($f->f->sis->sql)->get($degree)["academic_year_id"]);
        $programReview = $program->service->review($f->f->sis->program, $year, "Fictional combined test curriculum.");
        $program->service->publish($f->f->sis->program, $programReview["review_token"]);
        $review = $f->f->degrees->review($specialization, "Fictional combined specialization publication.");
        $version = $f->f->degrees->publish($specialization, $review["review_token"])["operation_id"];
        $f->f->degrees->save($degree, 0, new AuditRequirementsV3($d));
        $degreeReview = $f->f->degrees->review($degree, "Fictional combined degree publication.");
        $degreeVersion = new EntityId($f->f->degrees->publish($degree, $degreeReview["review_token"])["operation_id"]);
        $service = $this->service($f);
        $companions = [["template_id" => $specialization->value, "source" => "publication", "version_id" => $version]];
        $published = $service->testPublication(
            $degree,
            $degreeVersion,
            $f->student,
            $f->assignment,
            companions: $companions,
            sharing: \Domain\Catalog\ValueObject\ComponentCourseSharing::MultipleComponents,
        );
        self::assertSame("feasible", $published["outcome"]);
        $row = $f->f->groups->view($group);
        $g = $row["definition"];
        $g["name"] = "Changed unpublished group";
        $g["min_credits_milli"] = 6000;
        $f->f->groups->save($group, $row["revision"], new CourseGroupDefinitionV2($g));
        $before = $this->digest($f);
        $again = $service->testPublication(
            $degree,
            $degreeVersion,
            $f->student,
            $f->assignment,
            companions: $companions,
            sharing: \Domain\Catalog\ValueObject\ComponentCourseSharing::MultipleComponents,
        );
        self::assertSame($published, $again);
        self::assertSame($version, $again["components"][1]["progress"]["context"]["requirements_version_id"]);
        $mixed = $service->test(
            $degree,
            $f->student,
            $f->assignment,
            $definition,
            useGroupDrafts: true,
            companions: $companions,
            sharing: \Domain\Catalog\ValueObject\ComponentCourseSharing::MultipleComponents,
        );
        self::assertSame("infeasible", $mixed["outcome"]);
        self::assertSame("draft", $mixed["components"][0]["progress"]["context"]["test_source"]);
        self::assertSame("publication", $mixed["components"][1]["progress"]["context"]["test_source"]);
        self::assertSame($before, $this->digest($f));
    }
    public function testCombinedTrialsKeepReplayFailureAndAuthorityRevocationSeparateFromShortages(): void
    {
        [$f, $degree, $definition, $specialization] = $this->combinedFixture();
        $companions = [["template_id" => $specialization->value, "source" => "draft"]];
        $service = $this->service($f);
        $before = $this->digest($f);
        $limited = $service->test(
            $degree,
            $f->student,
            $f->assignment,
            $definition,
            budget: new HistoryReadBudget(maximumPages: 1, maximumEvents: 1),
            companions: $companions,
            sharing: \Domain\Catalog\ValueObject\ComponentCourseSharing::OneComponent,
        );
        self::assertSame(["status" => "limited", "mode" => "runtime_combined_test"], $limited);
        $f->f->sis->access->staff = false;
        $f->f->sis->access->grants = ["sis:catalog:manage"];
        try {
            $service->test(
                $degree,
                $f->student,
                $f->assignment,
                $definition,
                companions: $companions,
                sharing: \Domain\Catalog\ValueObject\ComponentCourseSharing::OneComponent,
            );
            self::fail("A combined test bypassed student-record authority.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        self::assertSame($before, $this->digest($f));
    }


    public function testCombinedTrialHttpInputPreservesExactSourcesAndRejectsMalformedOverrides(): void
    {
        [$f, $degree, $definition, $specialization] = $this->combinedFixture();
        $controller = new \Application\Http\Controller\SisDegreeAuditController($f->f->degrees, $this->service($f));
        $body = [
            "source" => "draft",
            "definition" => $definition->definition,
            "student_id" => $f->student->value,
            "assignment_id" => $f->assignment->value,
            "sharing" => "one_component",
            "companions" => [["template_id" => $specialization->value, "source" => "draft"]],
        ];
        $before = $this->digest($f);
        $request = new \Qubus\Http\ServerRequest()->withParsedBody($body);
        $response = $controller->test($request, $degree->value);
        $result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame("runtime_combined_test", $result["mode"]);
        self::assertSame("infeasible", $result["outcome"]);
        foreach (
            [
                ["sharing" => "not-a-policy"],
                ["companions" => "not-a-list"],
                [
                    "companions" => [
                        [
                            "template_id" => $specialization->value,
                            "source" => "publication",
                            "version_id" => EntityId::generate()->value,
                            "use_group_drafts" => true,
                        ],
                    ],
                ],
                ["source" => "publication", "version_id" => EntityId::generate()->value],
            ]
            as $override
        ) {
            try {
                $controller->test($request->withParsedBody(array_replace($body, $override)), $degree->value);
                self::fail("An invalid test source override reached evaluation.");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame($before, $this->digest($f));
    }


    public function testTemporaryGroupEligibilityDoesNotRewriteOverallEarnedCreditOrSaveResults(): void
    {
        $f = new StudentRequirementsFixture();
        $this->work($f);
        [$template, $degree, $group] = $this->draft($f);
        $d = $f->f->groups->view($group)["definition"];
        $d["work_eligibility"] = [
            "credit_origin" => "institutional",
            "completed_from" => "2020-01-01",
            "completed_through" => null,
        ];
        $f->f->groups->save($group, 0, new CourseGroupDefinitionV2($d));
        $before = $this->digest($f);
        $result = $this->service($f)->test($template, $f->student, $f->assignment, $degree);
        self::assertSame("needs_review", $result["allocation"]["outcome"]);
        self::assertContains("completion_date_unknown", $result["allocation"]["issues"]);
        self::assertSame($before, $this->digest($f));
        $d["work_eligibility"]["credit_origin"] = "transfer";
        $f->f->groups->save($group, 1, new CourseGroupDefinitionV2($d));
        $before = $this->digest($f);
        $excluded = $this->service($f)->test($template, $f->student, $f->assignment, $degree);
        self::assertSame("infeasible", $excluded["allocation"]["outcome"]);
        self::assertSame(
            "origin_excluded",
            $excluded["allocation"]["groups"][0]["course_review"][0]["attempts"][0]["status"],
        );
        self::assertSame("satisfied", $excluded["minimums"][0]["status"]);
        self::assertSame($result["minimums"], $excluded["minimums"]);
        self::assertSame($before, $this->digest($f));
        $d["work_eligibility"] = \Domain\DegreeAudit\ValueObject\GroupWorkEligibility::DEFAULT;
        $f->f->groups->save($group, 2, new CourseGroupDefinitionV2($d));
        $before = $this->digest($f);
        self::assertSame(
            "feasible",
            $this->service($f)->test($template, $f->student, $f->assignment, $degree)["allocation"][
                "outcome"
            ],
        );
        self::assertSame($before, $this->digest($f));
        self::assertNull($f->service->view($f->student, $f->assignment)["selection"]);
    }
    public function testRecencyTrialNeedsAnActualSelectedPursuitStartAndNeverInfersItFromToday(): void
    {
        $f = new StudentRequirementsFixture();
        $this->work($f);
        [$template, $degree, $group] = $this->draft($f);
        $d = $f->f->groups->view($group)["definition"];
        $d["work_eligibility"]["max_age_months_at_pursuit_start"] = 12;
        $f->f->groups->save($group, 0, new CourseGroupDefinitionV2($d));
        $s = $f->f->sis;
        $credit = $s->sql->rows("sis_academic_credit_streams", [], 1)[0];
        $change = $s
            ->academicDates()
            ->review(
                $f->student,
                new EntityId($credit["section_id"]),
                new EntityId($credit["enrollment_id"]),
                "2025-01-31",
                1,
                "Fictional actual completion.",
            );
        $s->academicDates()->commit(
            $f->student,
            new EntityId($credit["section_id"]),
            $change["review_token"],
        );
        $before = $this->digest($f);
        $unknown = $this->service($f)->test($template, $f->student, $f->assignment, $degree);
        self::assertSame("needs_review", $unknown["allocation"]["outcome"]);
        self::assertContains("pursuit_start_unknown", $unknown["allocation"]["issues"]);
        self::assertSame($before, $this->digest($f));
        $f->assign();
        $before = $this->digest($f);
        $known = $this->service($f)->test($template, $f->student, $f->assignment, $degree);
        self::assertSame(
            "2026-01-31",
            $known["allocation"]["groups"][0]["work_eligibility_context"]["pursuit_started_on"],
        );
        self::assertSame(
            "2025-01-31",
            $known["allocation"]["groups"][0]["work_eligibility_context"]["earliest_completion_on"],
        );
        self::assertSame($before, $this->digest($f));
    }
    public function testDraftReviewDateExpirationUsesTheCurrentReviewWithoutAssigningRequirements(): void
    {
        $f = new StudentRequirementsFixture();
        $this->work($f);
        [$template, $degree, $group] = $this->draft($f);
        $d = $f->f->groups->view($group)["definition"];
        $d["work_eligibility"]["max_age_months_at_evaluation"] = 12;
        $f->f->groups->save($group, 0, new CourseGroupDefinitionV2($d));
        $s = $f->f->sis;
        $credit = $s->sql->rows("sis_academic_credit_streams", [], 1)[0];
        $dates = $s->academicDates();
        $proof = $dates->review(
            $f->student,
            new EntityId($credit["section_id"]),
            new EntityId($credit["enrollment_id"]),
            "2025-08-10",
            1,
            "Fictional actual completion.",
        );
        $dates->commit($f->student, new EntityId($credit["section_id"]), $proof["review_token"]);
        $before = $this->digest($f);
        $current = $this->service($f)->test($template, $f->student, $f->assignment, $degree);
        self::assertSame("feasible", $current["allocation"]["outcome"]);
        self::assertSame(
            "2026-08-10",
            $current["allocation"]["groups"][0]["work_evaluation_context"]["evaluated_on"],
        );
        self::assertSame($before, $this->digest($f));
        $s->clock->time = $s->clock->time->modify("+1 day");
        $fresh = $this->service($f)->test($template, $f->student, $f->assignment, $degree);
        self::assertSame("infeasible", $fresh["allocation"]["outcome"]);
        self::assertSame($before, $this->digest($f));
    }

}
