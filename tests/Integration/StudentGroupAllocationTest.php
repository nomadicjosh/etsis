<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__) . "/Support/StudentRequirementsFixture.php";

use Domain\Catalog\Service\PublishedCourseReader;
use Domain\DegreeAudit\Repository\AcademicInputRepository;
use Domain\DegreeAudit\Service\AcademicInputService;
use Domain\DegreeAudit\Service\AcademicPolicyEvaluator;
use Domain\DegreeAudit\Service\AllocationGapAnalyzer;
use Domain\DegreeAudit\Service\CourseAllocationPlanner;
use Domain\DegreeAudit\Service\CourseMatchEvaluator;
use Domain\DegreeAudit\Service\GroupAllocationEvaluator;
use Domain\DegreeAudit\Service\PublishedRequirementsReader;
use Domain\DegreeAudit\Service\RequirementAdjustmentService;
use Domain\DegreeAudit\Service\RuleAllocationCompiler;
use Domain\DegreeAudit\Service\SavedCourseRulePreview;
use Domain\DegreeAudit\Service\StudentGroupAllocationService;
use Domain\DegreeAudit\ValueObject\AcademicInputInventory;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinition;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Section;
use Domain\Rules\Service\CourseRuleService;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAcademicInputRepository;
use Infrastructure\Persistence\Repository\SqlCourseRuleRepository;
use Infrastructure\Persistence\Repository\SqlReplacementCourseRepository;
use Infrastructure\Persistence\Repository\SqlRequirementAdjustmentRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;
use StudentRequirementsFixture;

final class StudentGroupAllocationTest extends TestCase
{
    private function adjustments(StudentRequirementsFixture $f): RequirementAdjustmentService
    {
        $s = $f->f->sis;
        return new RequirementAdjustmentService(
            new SqlRequirementAdjustmentRepository($s->sql),
            $f->service,
            new SqlStudentRecordRepository($s->sql),
            $f->f->history,
            $s->access,
            $s->transactions,
            $s->audit,
            $f->f->catalog->fixture->cipher,
            $s->clock,
            new PublishedRequirementsReader($f->f->history),
            new SqlReplacementCourseRepository($s->sql),
        );
    }
    private function service(
        StudentRequirementsFixture $f,
        ?AcademicInputRepository $repository = null,
        int $steps = 100000,
    ): StudentGroupAllocationService {
        $s = $f->f->sis;
        $students = new SqlStudentRecordRepository($s->sql);
        return new StudentGroupAllocationService(
            new AcademicInputService(
                $repository ?? new SqlAcademicInputRepository($s->sql),
                $s->academicCredits(),
                $s->transferCredits(),
                $s->enrollmentLedger(),
                $students,
                $f->service,
                $s->transactions,
            ),
            $f->service,
            $this->adjustments($f),
            new GroupAllocationEvaluator(
                new CourseMatchEvaluator(),
                new AcademicPolicyEvaluator(),
                new CourseAllocationPlanner($steps),
                new AllocationGapAnalyzer(),
                new RuleAllocationCompiler(new CourseMatchEvaluator()),
            ),
            $students,
            $s->transactions,
            new SavedCourseRulePreview(new PublishedCourseReader($f->f->history)),
        );
    }
    public function testSelectedRuleBackedRequirementsUseTheSameCapturedEligibilityAndWriteNothing(): void
    {
        $f = $this->institution();
        $this->work($f, 0);
        $s = $f->f->sis;
        $rules = new CourseRuleService(
            new SqlCourseRuleRepository($s->sql),
            new PublishedCourseReader($f->f->history),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->clock,
        );
        $snapshot = $rules->save(null, null, "DA.FICTIONAL", "Fictional named identity", [
            "condition" => "AND",
            "rules" => [
                [
                    "id" => "course_id",
                    "operator" => "equal",
                    "value" => $f->f->groupDefinition["courses"][0]["course_id"],
                ],
            ],
        ]);
        $id = EntityId::generate();
        $d = CourseGroupDefinitionV2::blank("Fictional saved rule")->definition;
        $d["min_credits_milli"] = 3000;
        $d["rules"] = [
            [
                "id" => EntityId::generate()->value,
                "mode" => "credits",
                "minimum_courses" => 0,
                "minimum_credits_milli" => 3000,
                "course_ids" => [],
                "eligibility" => $snapshot,
            ],
        ];
        $f->f->groups->create($id, new CourseGroupDefinitionV2($d));
        $review = $f->f->groups->review($id, "Fictional rule publication");
        $version = $f->f->groups->publish($id, $review["review_token"])["operation_id"];
        $row = $f->f->degrees->view($f->f->degree);
        $d = $row["definition"];
        $d["groups"] = [["group_id" => $id->value, "version_id" => $version]];
        $f->f->degrees->save($f->f->degree, $row["revision"], new AuditRequirementsV3($d));
        $f->version = new EntityId($f->f->publishDegree()["operation_id"]);
        $f->assign();
        $before = $f->f->digest("sis_history_events");
        $result = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame("feasible", $result["outcome"]);
        self::assertSame(3000, $result["groups"][0]["placements"][0]["credits_milli"]);
        self::assertSame($before, $f->f->digest("sis_history_events"));
    }

    public function testPublishedSubstitutionAndRuleUseTheSameAwardWithoutAnyReadSideWrites(): void
    {
        $f = $this->institution();
        $this->work($f, 3);
        $s = $f->f->sis;
        $required = $f->f->groupDefinition["courses"][4];
        $replacement = $f->f->groupDefinition["courses"][3];
        $rules = new CourseRuleService(
            new SqlCourseRuleRepository($s->sql),
            new PublishedCourseReader($f->f->history),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->clock,
        );
        $snapshot = $rules->save(null, null, "DA.FICTIONAL.SUBSTITUTE", "Fictional elective eligibility", [
            "condition" => "AND",
            "rules" => [["id" => "course_id", "operator" => "equal", "value" => $replacement["course_id"]]],
        ]);
        $group = EntityId::generate();
        $named = EntityId::generate();
        $dynamic = EntityId::generate();
        $d = CourseGroupDefinitionV2::blank("Fictional substitution and elective")->definition;
        $d["min_credits_milli"] = 3000;
        $d["courses"] = [$required];
        $d["rules"] = [
            [
                "id" => $named->value,
                "mode" => "all",
                "minimum_courses" => 1,
                "course_ids" => [$required["course_id"]],
            ],
            [
                "id" => $dynamic->value,
                "mode" => "credits",
                "minimum_courses" => 0,
                "minimum_credits_milli" => 3000,
                "course_ids" => [],
                "eligibility" => $snapshot,
            ],
        ];
        $f->f->groups->create($group, new CourseGroupDefinitionV2($d));
        $review = $f->f->groups->review($group, "Fictional combined requirements");
        $version = $f->f->groups->publish($group, $review["review_token"])["operation_id"];
        $degree = $f->f->definition;
        $degree["groups"] = [["group_id" => $group->value, "version_id" => $version]];
        $degree["min_credits_milli"] = 3000;
        $degree["min_resident_milli"] = 0;
        $row = $f->f->degrees->view($f->f->degree);
        $f->f->degrees->save($f->f->degree, $row["revision"], new AuditRequirementsV3($degree));
        $f->version = new EntityId($f->f->publishDegree()["operation_id"]);
        $f->assign();
        $state = function () use ($f, $s): array {
            $hashes = [];
            $tables = [
                "sis_history_events",
                "sis_history_streams",
                "sis_history_operations",
                "sis_audit",
                "sis_requirement_adjustments",
                "sis_student_requirements",
                "sis_academic_credits",
            ];
            foreach ($tables as $table) {
                $hashes[$table] = $f->f->digest($table);
            }
            $hashes["finance"] = hash("sha256", json_encode($s->financialEvents(), JSON_THROW_ON_ERROR));
            return $hashes;
        };
        $service = $this->service($f);
        $before = $state();
        self::assertSame("infeasible", $service->preview($f->student, $f->assignment)["outcome"]);
        self::assertSame($before, $state());
        $approvals = $this->adjustments($f);
        $review = $approvals->reviewGrant(
            $f->student,
            $f->assignment,
            [
                "kind" => "substitution",
                "course_id" => $required["course_id"],
                "replacement_course_id" => $replacement["course_id"],
                "replacement_version_id" => $replacement["version_id"],
            ],
            "Fictional registrar alternative",
        );
        $approvals->commit($f->student, $f->assignment, $review["review_token"]);
        $before = $state();
        $result = $service->preview($f->student, $f->assignment);
        self::assertSame("feasible", $result["outcome"]);
        self::assertCount(1, $result["groups"][0]["placements"]);
        $p = $result["groups"][0]["placements"][0];
        self::assertSame($replacement["course_id"], $p["course_id"]);
        self::assertSame($required["course_id"], $p["target_course_id"]);
        self::assertSame(3000, $p["credits_milli"]);
        self::assertTrue($p["via_substitution"]);
        $contributions = [$named->value => 3000, $dynamic->value => 3000];
        ksort($contributions, SORT_STRING);
        self::assertSame($contributions, $p["rule_credits_milli"]);
        self::assertSame([$replacement["course_id"]], $result["groups"][0]["rules"][1]["course_ids"]);
        self::assertSame(
            [$replacement["course_id"]],
            $result["groups"][0]["gaps"]["rules"][1]["eligible_course_ids"],
        );
        self::assertSame($result, $service->preview($f->student, $f->assignment));
        self::assertSame($before, $state());
        $revoke = $approvals->reviewRevoke(
            $f->student,
            $f->assignment,
            new EntityId($review["adjustment"]["adjustment_id"]),
            "Fictional registrar revocation",
        );
        $approvals->commit($f->student, $f->assignment, $revoke["review_token"]);
        $before = $state();
        self::assertSame("infeasible", $service->preview($f->student, $f->assignment)["outcome"]);
        self::assertSame($before, $state());
    }

    public function testIndividualPublicationApprovalsCoexistExpireAndRevokeWithoutChangingAcademicFacts(): void
    {
        $f = $this->institution();
        $s = $f->f->sis;
        $original = $f->f->groupDefinition["courses"][0];
        $course = new EntityId($original["course_id"]);
        $pub = $f->f->catalog->publications();
        $s->sql->update(
            "sis_courses",
            ["title" => "Fictional accepted later title", "credits_milli" => 4000],
            ["id" => $course->value],
        );
        $r = $pub->review($course, "Fictional later course");
        $later = $pub->publish($course, $r["review_token"])["operation_id"];
        $f->f->groupDefinition["courses"][0]["version_id"] = $later;
        $this->work($f, 0);
        $f->f->groupDefinition["courses"][0] = $original;
        $group = EntityId::generate();
        $d = CourseGroupDefinitionV2::blank("Fictional version exception")->definition;
        $d["min_credits_milli"] = 3000;
        $d["courses"] = [$original];
        $d["rules"] = [
            [
                "id" => EntityId::generate()->value,
                "mode" => "all",
                "minimum_courses" => 1,
                "course_ids" => [$course->value],
            ],
        ];
        $f->f->groups->create($group, new CourseGroupDefinitionV2($d));
        $review = $f->f->groups->review($group, "Fictional strict curriculum");
        $gv = $f->f->groups->publish($group, $review["review_token"])["operation_id"];
        $dd = $f->f->definition;
        $dd["groups"] = [["group_id" => $group->value, "version_id" => $gv]];
        $row = $f->f->degrees->view($f->f->degree);
        $f->f->degrees->save($f->f->degree, $row["revision"], new AuditRequirementsV3($dd));
        $f->version = new EntityId($f->f->publishDegree()["operation_id"]);
        $f->assign();
        $service = $this->service($f);
        self::assertSame("needs_review", $service->preview($f->student, $f->assignment)["outcome"]);
        $academic = $f->f->digest("sis_academic_credits");
        $finance = $s->financialEvents();
        $approvals = $this->adjustments($f);
        $definition = ["kind" => "course_version", "course_id" => $course->value, "version_id" => $later];
        $review = $approvals->reviewGrant(
            $f->student,
            $f->assignment,
            $definition,
            "Fictional registrar acceptance",
        );
        $receipt = $approvals->commit($f->student, $f->assignment, $review["review_token"]);
        $result = $service->preview($f->student, $f->assignment);
        self::assertSame("feasible", $result["outcome"]);
        $p = $result["groups"][0]["placements"][0];
        self::assertTrue($p["via_substitution"]);
        self::assertSame(4000, $p["credits_milli"]);
        self::assertSame("Fictional accepted later title", $p["course_title"]);
        self::assertNotSame($p["course_title"], $p["target_title"]);
        $s->sql->update("sis_courses", ["title" => "Fictional third publication"], ["id" => $course->value]);
        $r = $pub->review($course, "Fictional third course");
        $third = $pub->publish($course, $r["review_token"])["operation_id"];
        $extra = $approvals->reviewGrant(
            $f->student,
            $f->assignment,
            ["kind" => "course_version", "course_id" => $course->value, "version_id" => $third],
            "Fictional second allowance",
        );
        $approvals->commit($f->student, $f->assignment, $extra["review_token"]);
        self::assertCount(
            2,
            $approvals->view($f->student, $f->assignment)["effective_minimums"]["accepted_course_versions"][
                $course->value
            ],
        );
        $revoke = $approvals->reviewRevoke(
            $f->student,
            $f->assignment,
            new EntityId($review["adjustment"]["adjustment_id"]),
            "Fictional revoke first allowance",
        );
        $approvals->commit($f->student, $f->assignment, $revoke["review_token"]);
        self::assertSame("needs_review", $service->preview($f->student, $f->assignment)["outcome"]);
        $s->clock->time = $s->clock->time->modify("+21 minutes");
        self::assertSame($receipt, $approvals->commit($f->student, $f->assignment, $review["review_token"]));
        self::assertSame("needs_review", $service->preview($f->student, $f->assignment)["outcome"]);
        self::assertSame($academic, $f->f->digest("sis_academic_credits"));
        self::assertSame($finance, $s->financialEvents());
        $expired = $approvals->reviewGrant(
            $f->student,
            $f->assignment,
            $definition,
            "Fictional expired new allowance",
        );
        $before = $f->f->digest("sis_history_events");
        $s->clock->time = $s->clock->time->modify("+21 minutes");
        try {
            $approvals->commit($f->student, $f->assignment, $expired["review_token"]);
            self::fail("Expired review accepted.");
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertSame($before, $f->f->digest("sis_history_events"));
        }
    }

    private function institution(): StudentRequirementsFixture
    {
        $f = new StudentRequirementsFixture();
        $f->f->sis->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 90000,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ]);
        $f->assign();
        return $f;
    }
    private function work(StudentRequirementsFixture $f, int $index, bool $graded = true): array
    {
        $s = $f->f->sis;
        $course = $f->f->groupDefinition["courses"][$index];
        $section = new Section(
            EntityId::generate(),
            new EntityId($course["course_id"]),
            $s->term,
            $s->access->actor,
            "FICTIONAL." . $index,
            20,
            true,
            true,
            new Money(0, "USD"),
        );
        $s->registration->addSection($section);
        $bindings = $f->f->catalog->bindings();
        $review = $bindings->review(
            $section->id,
            new EntityId($course["version_id"]),
            "Fictional retained offering.",
        );
        $bindings->commit($section->id, $review["review_token"]);
        $enrollment = $s->service->register($f->student, $section->id)->id;
        if ($graded) {
            $ledger = $s->academicCredits();
            $grade = new EntityId($ledger->choices($f->student, $section->id, $enrollment)["items"][0]["id"]);
            $review = $ledger->review($f->student, $section->id, $enrollment, $grade, 0);
            $ledger->commit($f->student, $section->id, $review["review_token"]);
        }
        return [
            $section,
            $enrollment,
            new EntityId($s->sql->one("sis_academic_credits", ["source_id" => $enrollment->value])["id"]),
        ];
    }
    private function grant(StudentRequirementsFixture $f, array $definition): array
    {
        $s = $this->adjustments($f);
        $r = $s->reviewGrant($f->student, $f->assignment, $definition, "Fictional registrar exception.");
        return $s->commit($f->student, $f->assignment, $r["review_token"]);
    }
    public function testRetainedRequirementsAndVerifiedWorkProduceAReadOnlyCompleteGroupPlacement(): void
    {
        $f = $this->institution();
        foreach ([0, 1, 2, 4] as $index) {
            $this->work($f, $index);
        }
        $s = $f->f->sis;
        $before = $s->sql->rows("sis_history_events");
        $receipts = $s->sql->rows("sis_history_operations");
        $result = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame("review", $result["status"]);
        self::assertSame("feasible", $result["outcome"]);
        self::assertSame($f->version->value, $result["context"]["requirements_version_id"]);
        self::assertCount(4, $result["groups"][0]["placements"]);
        self::assertSame(12000, array_sum(array_column($result["groups"][0]["placements"], "credits_milli")));
        self::assertFalse($result["groups"][0]["gaps"]["has_shortfall"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame($receipts, $s->sql->rows("sis_history_operations"));
        self::assertArrayNotHasKey("references", $result);
        self::assertArrayNotHasKey("actor_id", $result);
    }
    public function testCompetingRetainedGroupsExplainTheSharedShortageWithoutWritingOrAssigningWork(): void
    {
        $f = $this->institution();
        $s = $f->f->sis;
        $other = EntityId::generate();
        $definition = $f->f->groupDefinition;
        $definition["name"] = "Fictional professional foundations";
        $f->f->groups->create($other, new CourseGroupDefinition($definition));
        $review = $f->f->groups->review($other, "Fictional shared course-group approval.");
        $publication = $f->f->groups->publish($other, $review["review_token"]);
        $f->f->definition["groups"][] = ["group_id" => $other->value, "version_id" => $publication["operation_id"]];
        $f->f->degrees->save(
            $f->f->degree,
            $f->f->degrees->view($f->f->degree)["revision"],
            new AuditRequirementsV3($f->f->definition),
        );
        $f->version = new EntityId($f->f->publishDegree()["operation_id"]);
        $f->assign();
        foreach ([0, 1, 2, 4] as $index) {
            $this->work($f, $index);
        }
        $events = $s->sql->rows("sis_history_events");
        $receipts = $s->sql->rows("sis_history_operations");
        $finance = $s->financialEvents();
        $r = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame("infeasible", $r["outcome"]);
        self::assertSame(12000, $r["competition"]["conflicts"][0]["credit_shortfall_milli"]);
        self::assertSame(2, $r["competition"]["conflicts"][0]["course_shortfall"]);
        foreach ($r["groups"] as $group) {
            self::assertFalse($group["gaps"]["has_shortfall"]);
            self::assertSame([], $group["placements"]);
        }
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        self::assertSame($receipts, $s->sql->rows("sis_history_operations"));
        self::assertSame($finance, $s->financialEvents());
        $f->f->definition["policies"]["group_reuse"] = "multiple_groups";
        $f->f->degrees->save(
            $f->f->degree,
            $f->f->degrees->view($f->f->degree)["revision"],
            new AuditRequirementsV3($f->f->definition),
        );
        $f->version = new EntityId($f->f->publishDegree()["operation_id"]);
        $f->assign();
        $r = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame("feasible", $r["outcome"]);
        self::assertNull($r["competition"]);
        self::assertCount(4, $r["groups"][0]["placements"]);
        self::assertCount(4, $r["groups"][1]["placements"]);
        $academic = $s->sql->rows("sis_academic_credits");
        $finance = $s->financialEvents();
        $approvals = $this->adjustments($f);
        $course = $f->f->groupDefinition["courses"][0]["course_id"];
        $review = $approvals->reviewGrant($f->student, $f->assignment,
            ["kind" => "placement", "course_id" => $course, "group_id" => $f->f->group->value],
            "Fictional registrar placement preference.");
        $approvals->commit($f->student, $f->assignment, $review["review_token"]);
        $r = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame("infeasible", $r["outcome"]);
        self::assertContains("placement_restricted", array_merge(...array_column($r["records"], "statuses")));
        foreach ($r["groups"] as $group) self::assertSame([], $group["placements"]);
        self::assertSame($academic, $s->sql->rows("sis_academic_credits"));
        self::assertSame($finance, $s->financialEvents());
        $revoke = $approvals->reviewRevoke($f->student, $f->assignment,
            new EntityId($review["adjustment"]["adjustment_id"]), "Fictional restore normal sharing.");
        $approvals->commit($f->student, $f->assignment, $revoke["review_token"]);
        self::assertSame("feasible", $this->service($f)->preview($f->student, $f->assignment)["outcome"]);

    }
    public function testWaiverDoesNotCreateCreditsAndSeparateApprovedMinimumReductionChangesPlacement(): void
    {
        $f = $this->institution();
        foreach ([1, 2, 4] as $index) {
            $this->work($f, $index);
        }
        $service = $this->service($f);
        self::assertSame("infeasible", $service->preview($f->student, $f->assignment)["outcome"]);
        $this->grant($f, [
            "kind" => "waiver",
            "course_id" => $f->f->groupDefinition["courses"][0]["course_id"],
        ]);
        self::assertSame("infeasible", $service->preview($f->student, $f->assignment)["outcome"]);
        $this->grant($f, [
            "kind" => "minimum",
            "group_id" => $f->f->group->value,
            "minimum" => "min_credits_milli",
            "required_milli" => 9000,
        ]);
        $result = $service->preview($f->student, $f->assignment);
        self::assertSame("feasible", $result["outcome"]);
        self::assertCount(3, $result["groups"][0]["placements"]);
        self::assertSame(9000, $result["groups"][0]["required_credits_milli"]);
        self::assertSame(1, count($result["groups"][0]["waived_courses"]));
    }
    public function testUngradedWorkIsExplainedAndNoSpeculativePartialPlacementIsReturned(): void
    {
        $f = $this->institution();
        $this->work($f, 0, false);
        $result = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame("infeasible", $result["outcome"]);
        self::assertContains("in_progress", $result["records"][0]["statuses"]);
        self::assertSame([], $result["groups"][0]["placements"]);
        self::assertTrue($result["groups"][0]["gaps"]["has_shortfall"]);
        self::assertSame(12000, $result["groups"][0]["gaps"]["credit_shortfall_milli"]);
        self::assertSame([], $result["groups"][0]["gaps"]["eligible_target_ids"]);
    }
    public function testNewCatalogPublicationsDoNotReplaceTheSelectedGroupCourseReferences(): void
    {
        $f = $this->institution();
        foreach ([0, 1, 2, 4] as $index) {
            $this->work($f, $index);
        }
        $s = $f->f->sis;
        $course = new EntityId($f->f->groupDefinition["courses"][0]["course_id"]);
        $s->sql->update("sis_courses", ["title" => "Changed today"], ["id" => $course->value]);
        $review = $f->f->catalog->publications()->review($course, "Fictional future catalog revision.");
        $f->f->catalog->publications()->publish($course, $review["review_token"]);
        $result = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame("feasible", $result["outcome"]);
        self::assertStringNotContainsString("Changed today", json_encode($result, JSON_THROW_ON_ERROR));
    }
    public function testSharedReplayAndSearchLimitsProduceDistinctControlledResults(): void
    {
        $f = $this->institution();
        foreach ([0, 1, 2, 4] as $index) {
            $this->work($f, $index);
        }
        self::assertSame(
            ["status" => "limited"],
            $this->service($f)->preview($f->student, $f->assignment, new HistoryReadBudget(maximumPages: 2)),
        );
        $result = $this->service($f, steps: 1)->preview($f->student, $f->assignment);
        self::assertSame("undetermined", $result["outcome"]);
        self::assertSame([], $result["groups"][0]["placements"]);
    }
    public function testMissingProjectionAndDamagedApprovalHistoryCannotBecomePartialPlacement(): void
    {
        foreach (["projection", "approval"] as $kind) {
            $f = $this->institution();
            [, , $credit] = $this->work($f, 0);
            $s = $f->f->sis;
            if ($kind === "projection") {
                $s->sql->execute("DELETE FROM sis_academic_credits WHERE id = ?", [$credit->value]);
            } else {
                $this->grant($f, [
                    "kind" => "waiver",
                    "course_id" => $f->f->groupDefinition["courses"][0]["course_id"],
                ]);
                $head = $s->sql->one("sis_history_streams", [
                    "stream_type" => "student_requirement_adjustments",
                    "aggregate_id" => $f->assignment->value,
                ]);
                $s->sql->update(
                    "sis_history_events",
                    ["event_hash" => str_repeat("0", 64)],
                    ["stream_id" => $head["id"]],
                );
            }
            self::assertSame(
                ["status" => "inconsistent"],
                $this->service($f)->preview($f->student, $f->assignment),
            );
        }
    }
    public function testEarlyRemovedWorkNeverAppearsInPlacementOrCounts(): void
    {
        $f = $this->institution();
        [$section, $enrollment, $credit] = $this->work($f, 0, false);
        $s = $f->f->sis;
        $r = $s->drops->review($f->student, $section->id);
        $s->drops->commit(
            $f->student,
            $section->id,
            new EntityId($r["enrollment_id"]),
            $r["version"],
            new EntityId($r["operation_id"]),
            $r["revision"],
            $r["effective_on"],
        );
        $result = $this->service($f)->preview($f->student, $f->assignment);
        self::assertSame(0, $result["current_record_count"]);
        self::assertSame([], $result["records"]);
        self::assertStringNotContainsString($credit->value, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($enrollment->value, json_encode($result, JSON_THROW_ON_ERROR));
    }
    public function testOwnerOfficeAndPursuitScopeAreVerifiedAndRevokedAccessCannotReturnAResult(): void
    {
        $f = $this->institution();
        $s = $f->f->sis;
        $s->access->staff = false;
        self::assertSame("review", $this->service($f)->preview($f->student, $f->assignment)["status"]);
        $s->access->actor = $s->user();
        $s->access->grants = ["sis:student:read"];
        try {
            $this->service($f)->preview($f->student, EntityId::generate());
            self::fail("Foreign pursuit accepted.");
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $repository = new class ($s) implements AcademicInputRepository {
            public function __construct(private \SisFixture $s) {}
            public function inventory(EntityId $student): AcademicInputInventory
            {
                $inventory = new SqlAcademicInputRepository($this->s->sql)->inventory($student);
                $this->s->access->grants = [];
                return $inventory;
            }
        };
        $this->expectException(AccessDeniedException::class);
        $this->service($f, $repository)->preview($f->student, $f->assignment);
    }
    public function testMissingSelectionIsSetupGuidanceInsteadOfAnEmptyCompletedAudit(): void
    {
        $f = new StudentRequirementsFixture();
        self::assertSame(
            ["status" => "needs_selection"],
            $this->service($f)->preview($f->student, $f->assignment),
        );
    }
    public function testApprovedAlternativeIsVerifiedAndPlacedWithoutConsumingItsOriginalTargetTwice(): void
    {
        $f = $this->institution();
        foreach ([0, 1, 2, 3] as $index) {
            $this->work($f, $index);
        }
        $service = $this->service($f);
        self::assertSame("infeasible", $service->preview($f->student, $f->assignment)["outcome"]);
        $required = $f->f->groupDefinition["courses"][4];
        $replacement = $f->f->groupDefinition["courses"][3];
        $this->grant($f, ["kind" => "substitution", "course_id" => $required["course_id"],
            "replacement_course_id" => $replacement["course_id"], "replacement_version_id" => $replacement["version_id"]]);
        $before = $f->f->sis->sql->rows("sis_history_events");
        $result = $service->preview($f->student, $f->assignment);
        self::assertSame("feasible", $result["outcome"]);
        self::assertCount(4, $result["groups"][0]["placements"]);
        $alternative = array_values(array_filter($result["groups"][0]["placements"], fn($p) => $p["via_substitution"]));
        self::assertCount(1, $alternative);
        self::assertSame($replacement["course_id"], $alternative[0]["course_id"]);
        self::assertSame($required["course_id"], $alternative[0]["target_course_id"]);
        self::assertSame($before, $f->f->sis->sql->rows("sis_history_events"));
    }

}
