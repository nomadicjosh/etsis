<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/StudentRequirementsFixture.php';

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
        );
    }

    private function digest(StudentRequirementsFixture $f): string
    {
        $rows = [];
        foreach ([
            'sis_history_events', 'sis_history_streams', 'sis_history_operations',
            'sis_degree_audit_templates', 'sis_specialization_audit_templates', 'sis_course_groups', 'sis_student_requirements', 'sis_course_rules',
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
        self::assertStringNotContainsString('eligibility', json_encode($private));
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

}
